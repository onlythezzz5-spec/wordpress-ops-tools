#!/usr/bin/env python3
"""
GPT Image Engine v3 — Strategy + Render pipeline (TikHub backend)
GPT-4o: 9-shot strategy (desire core, headlines, prompts, PT-BR copy)
gpt-image-2: Image rendering per shot via chat completions
"""

import os, json, time, logging, base64, re
from pathlib import Path
from datetime import datetime
from concurrent.futures import ThreadPoolExecutor, as_completed
import requests

ROOT = Path(__file__).parent
IMAGE_INPUT_DIR = ROOT / "image_input"
IMAGE_OUTPUT_DIR = ROOT / "image_output"
STATES_FILE = ROOT / "image_states.json"

log = logging.getLogger("image_pipeline")

STYLE_PRESETS = {
    "amazon": "Amazon clean professional, pure white background, soft even lighting, catalog ready",
    "tiktok": "TikTok viral, vibrant Gen Z colors, dynamic energy, bold pop aesthetic",
    "ozon": "Ozon Russian marketplace, clean light grey background, professional minimal",
    "mercadolivre": "Mercado Livre Brazil, warm natural light, trustworthy lifestyle feel",
    "minimal": "Minimalist studio, white void, sharp detail, 8K commercial",
    "luxury": "Luxury dark moody, dramatic rim lighting, gold accents, premium magazine",
    "gaming_rgb": "RGB gaming aesthetic, dark background, ARGB rainbow lighting, sharp metallic textures, PC build photography",
    "industrial_tech": "Industrial tech precision, carbon fiber texture, cold blue accent lights, engineering catalog style, 8K commercial",
    "thermal_hero": "Thermal imaging meets product photography, heat gradient overlay, sci-fi HUD elements, dark atmospheric background",
    "ozon_tech": "Ozon Russian tech marketplace, structured dark card layout, cyan-blue accent, specs-forward, premium 8K render",
}

ASPECT_RATIOS = {
    "1:1":  "1024x1024",
    "4:5":  "1024x1280",
    "9:16": "1080x1920",
    "3:4":  "1024x1365",
    "16:9": "1792x1024",
}

RATIO_CONTEXT = {
    "1:1": "Square 1:1, ideal for Amazon/Shopee main image, product centered with clean background",
    "4:5": "Vertical 4:5, ideal for Instagram feed, slightly taller than square, product with lifestyle context",
    "9:16": "Full vertical 9:16, TikTok/Reels mobile-first, bold compositions, text-safe top and bottom margins",
    "3:4": "Vertical 3:4, marketplace detail/listing format for Ozon/MercadoLivre, product detail focused, technical specs layout",
    "16:9": "Wide 16:9, banner/header format, product in horizontal context, storytelling composition",
}

# Platform hints
PLATFORM_HINTS = {
    "TikTok": "Short-form vertical video style, bold text overlay, instant visual impact, trending aesthetic. DESIGN FOR MOBILE — text must be large and readable on small screens.",
    "Shopee": "Search-driven marketplace, parameter-forward, feature callouts, clean comparison style, price-value emphasis.",
    "DirectResponse": "Meta ads style, pain-point driven, credibility badges, persuasive copy, before/after tension.",
    "Ozon": """Russian marketplace (Ozon/WB) — PC/Tech Hardware category.
- LANGUAGE: ALL text in Russian, bold readable font
- LAYOUT: Structured tech card — logo top center, spec badges on sides, large numbers, compatibility list
- VISUAL: Dark carbon fiber / space black background, cold blue/cyan accent lighting, metallic sharp textures, 8K commercial render
- NUMBERS-DRIVEN: Feature callouts with numeric values (240W TDP, 1800 RPM, 25 dB, 6 тепловых трубок)
- COMPOSITION: Hero product shot + spec labels + RGB light effects + thermal visualization
- MOOD: Ultra realistic tech commercial, sharp metallic details, premium engineering atmosphere""",
}

# Trigger hints
TRIGGER_HINTS = {
    "Impulse": "RAW PAIN & DESIRE. Show sweat, muscle strain, gripping tension, extreme close-ups, dramatic contrast. Trigger instinct.",
    "Rational": "ABSOLUTE PERFORMANCE. Show specs, mechanisms, why this works better. Technical authority, precision, data-driven.",
    "Premium": "STATUS & IDENTITY. Aspirational lifestyle, luxury context, exclusivity, refined aesthetics, belonging.",
}

_session = requests.Session()
_session.trust_env = False


def _configure_session():
    """Use direct TikHub access by default; only use OPENAI_PROXY when explicitly set."""
    _session.trust_env = False
    _session.proxies.clear()
    proxy = os.getenv("OPENAI_PROXY", "").strip()
    if proxy:
        _session.proxies.update({"https": proxy, "http": proxy})


def _download_with_retry(url, timeout=60, retries=3):
    """Download image with retry and backoff."""
    for attempt in range(retries):
        try:
            r = _session.get(url, timeout=timeout)
            r.raise_for_status()
            return r
        except Exception as e:
            if attempt == retries - 1:
                raise
            time.sleep(2 * (attempt + 1))


def load_config():
    env_path = ROOT / ".env"
    if env_path.exists():
        with open(env_path, encoding="utf-8") as f:
            for line in f:
                line = line.strip()
                if line and not line.startswith("#") and "=" in line:
                    k, v = line.split("=", 1)
                    os.environ[k.strip()] = v.strip()
    _configure_session()


def _get_key():
    load_config()
    k = os.getenv("OPENAI_API_KEY", "")
    if not k: raise RuntimeError("请在 .env 中设置 OPENAI_API_KEY")
    return k


def _get_base_url():
    return os.getenv("OPENAI_BASE_URL", "https://api.openai.com/v1")


def _chat_endpoint():
    return f"{_get_base_url()}/chat/completions"


def _image_model():
    return os.getenv("IMAGE_GEN_MODEL", "gpt-image-2")


def _load_images_base64(pid: str, image_names: list) -> list:
    """Load project images as base64 dicts."""
    result = []
    for name in (image_names or []):
        p = IMAGE_INPUT_DIR / pid / name
        if p.exists():
            ext = p.suffix.lower()
            mime = {"jpg": "image/jpeg", "jpeg": "image/jpeg", "png": "image/png", "webp": "image/webp"}.get(ext, "image/jpeg")
            result.append({"mime": mime, "data": base64.b64encode(p.read_bytes()).decode()})
    return result




def normalize_strategy_schema(strategy: dict) -> dict:
    """Normalize newer RU/CN strategy fields to the legacy frontend fields too."""
    if not isinstance(strategy, dict):
        return {"shots": []}
    if "desire_core_pt" not in strategy and strategy.get("desire_core_ru"):
        strategy["desire_core_pt"] = strategy.get("desire_core_ru", "")
    shots = strategy.get("shots") or []
    fixed = []
    for i, shot in enumerate(shots[:9], 1):
        if not isinstance(shot, dict):
            continue
        shot["id"] = int(shot.get("id") or i)
        shot.setdefault("angle_name", shot.get("angle_name_ru") or f"{shot['id']}. Shot")
        shot.setdefault("angle_name_cn", f"{shot['id']}. ??")
        shot.setdefault("headline", shot.get("headline_ru") or shot.get("headline") or "")
        shot.setdefault("headline_cn", shot.get("headline_cn") or "")
        shot.setdefault("bullets", shot.get("bullets_ru") or shot.get("bullets") or [])
        shot.setdefault("bullets_cn", shot.get("bullets_cn") or [])
        shot.setdefault("pt_desc", shot.get("desc_ru") or shot.get("pt_desc") or "")
        shot.setdefault("pt_desc_cn", shot.get("desc_cn") or shot.get("pt_desc_cn") or "")
        shot.setdefault("big_number", shot.get("big_number") or _extract_big_number(shot))
        bn = str(shot.get("big_number") or "").strip().replace("～C", "\u00b0C")
        if bn and not re.search(r'(\u00b0C|\u2103|C|W|\u0412\u0442|dB|\u0434\u0411|RPM|\u043e\u0431/\u043c\u0438\u043d|CFM|%|RGB|AI|TEC|LOW NOISE|MAGNET)\s*$', bn, re.I):
            bn = _extract_big_number(shot)
        shot["big_number"] = bn
        fixed.append(shot)
    strategy["shots"] = fixed
    return strategy


def _extract_big_number(shot: dict) -> str:
    text = " ".join(str(shot.get(k, "")) for k in ("headline", "headline_ru", "pt_desc", "desc_ru", "img_prompt"))
    text += " " + " ".join(map(str, shot.get("bullets", []) or shot.get("bullets_ru", []) or []))
    patterns = [
        r'[-+]?\d+(?:[\.,]\d+)?\s*(?:\u00b0C|\u2103|\?C|C)',
        r'\d+(?:[\.,]\d+)?\s*(?:W|\u0412\u0442)',
        r'\d+(?:[\.,]\d+)?\s*(?:dB|\u0434\u0411)',
        r'\d+(?:[\.,]\d+)?\s*(?:RPM|\u043e\u0431/\u043c\u0438\u043d|CFM|%)',
    ]
    for pat in patterns:
        m = re.search(pat, text, re.I)
        if m:
            return m.group(0).strip()
    if re.search(r'\bRGB\b', text, re.I):
        return "RGB"
    if re.search(r'\bAI\b', text, re.I):
        return "AI"
    return ""

# ====== GPT-4o Strategy Generation ======
OZON_STRATEGY_SYSTEM = """You are an elite Ozon/Wildberries visual strategist for premium consumer electronics.
You are building Russian marketplace product cards from a reference-table workflow:
PRODUCT REFERENCE + SCENE REFERENCE + COPY LAYOUT REFERENCE + ART DIRECTION.

NON-NEGOTIABLE METHOD:
- One image = one selling point = one visual proof.
- The product is the evidence. It must be large, sharp, faithful to reference images, and visually dominant.
- Russian text must be generated as part of the image design. Never describe external/Pillow/canvas overlay.
- Avoid cheap collage, random icons, noisy HUD, crowded parameter walls, fake unrelated products, and distorted product geometry.
- Use Ozon/WB style hierarchy: brand/logo area, big headline, one large number when useful, one short explanation, product proof scene.
- Main image may contain 3 key specs; every other image should focus on one core point.

PREMIUM TECH VISUAL LANGUAGE:
- Category: magnetic phone cooler, semiconductor cooling, fan, phone accessory, gaming hardware.
- Mood: high-end 3D commercial render, graphite black, satin metal, cold cyan rim light, controlled RGB, glass/metal reflections.
- Effects are restrained and purposeful. Use only ONE primary effect per card: thermal gradient OR frost OR airflow OR app UI OR exploded technical parts.
- Text palette: white, ice blue, restrained yellow. Bold readable Russian typography.
- Product share of frame: main image 60-70%; other cards 45-60%.

9-SHOT SALES CHAIN FOR PHONE COOLER:
1. MAIN IMAGE: product hero, clean premium thumbnail, product name + 3 core specs max.
2. COOLING RESULT: peak cooling number, show hot-to-cold transformation.
3. WHY IT COOLS: semiconductor plate + airflow path, explain mechanism.
4. 25W POWER: high power and heat dissipation efficiency, energy core visual.
5. LOW NOISE: quiet fan/motor, small sound waves, calm setup.
6. MAGNETIC MOUNT: phone attachment, alignment, stable magnetic ring.
7. APP + AI CONTROL: phone app interface, modes, AI temperature control.
8. RGB DESIGN: controlled RGB lighting, gaming premium aesthetics.
9. KIT + TRUST: 45W GaN charger, packaging, accessories, reliability.

OUTPUT RULES:
- Return valid JSON only. No markdown.
- EXACTLY 9 shots, ids 1..9.
- Every shot must include Russian fields and Chinese translation fields.
- Every img_prompt must include: exact visible Russian text, Chinese intent in layout_notes_cn, product placement, lighting, background, ONE visual effect, and no-distortion rule.
- Keep text short. No more than 1 headline + 1 big number + 1 short explanation, except main image.
- Use product facts when present: Piva B3Pro, magnetic phone cooler, semiconductor cooling, low noise, app control, RGB lighting, custom efficiency modes, 25W power, peak cooling -19.5C, AI temperature control, 45W GaN charger kit.

JSON schema:
{
  "desire_core_ru": "short Russian core selling point",
  "desire_core_cn": "??????",
  "shots": [{
    "id": 1,
    "angle_name_ru": "1. ??????? ????",
    "angle_name_cn": "1. ??",
    "headline_ru": "Russian headline shown on image",
    "headline_cn": "????",
    "big_number": "main number, e.g. -19.5C or 25W",
    "bullets_ru": ["short Russian spec 1", "short Russian spec 2"],
    "bullets_cn": ["????1", "????2"],
    "desc_ru": "short Russian explanation, one sentence",
    "desc_cn": "????",
    "layout_notes_cn": "???????????/????/????",
    "img_prompt": "English prompt with exact visible Russian text. Premium integrated layout. Product must match reference exactly."
  }]
}"""

STRATEGY_SYSTEM = """You are an elite e-commerce conversion strategist and art director for Brazilian marketplaces.

Given a product and reference images, generate a 9-image CONVERSION WEAPON funnel. Each image must trigger visceral buying desire.

FUNNEL STRUCTURE (9 shots, exact order):
1. Hero Shot (VISUAL ATTACK) — Low angle, massive product, aggressive headline + 2 tags. TEXTURE AGGRESSION.
2. Core Feature — High contrast visual, 1 title + 3 bullet points. Show key differentiation.
3. Dramatic Contrast — THE CONTRAST ENGINE. Ordinary vs Pro. Visual conflict. 1 title + 2 comparative phrases.
4. Material Detail (TEXTURE AGGRESSION) — Micro-texture, raw details, material quality. 1 title + 2 specs.
5. Persona Immersion (HUMAN DOMINANCE) — Intense human interaction, hands gripping, physical tension, sweat, muscle. 1 action headline.
6. Specs & Size — Technical authority. 1 title + 3 numerical bullets. Size reference.
7. Trust/Endorsement — Credibility vibe. Badge + 2 trust bullets. Certifications, guarantees.
8. Social Proof — Real human benefit. 1 user quote. Emotional transformation.
9. CTA — Urgency. "Compre Agora" + 3 summary bullets.

CRITICAL RULES:
- ALL copy in Brazilian Portuguese (pt-BR)
- Headlines: SHORT, AGGRESSIVE, UPPERCASE (max 4 words)
- Image prompts: photorealistic, detailed lighting/camera/texture description
- Product identity MUST match reference images exactly
- Every img_prompt must specify the visual energy, lighting, and composition

Return ONLY valid JSON, no markdown:
{
  "desire_core_pt": "One sentence: the primal desire this product fulfills (in pt-BR)",
  "desire_core_cn": "中文核心欲望",
  "shots": [
    {
      "id": 1,
      "angle_name": "1. Hero Shot",
      "layout_type": "Hero",
      "headline": "FORÇA BRUTA",
      "headline_cn": "中文标题",
      "bullets": ["bullet1", "bullet2"],
      "bullets_cn": ["卖点1", "卖点2"],
      "pt_desc": "SEO-friendly description paragraph in pt-BR",
      "img_prompt": "Photorealistic scene. [Specific camera angle, lighting setup, texture details, composition]"
    }
  ]
}"""


def _is_phone_cooler_product(product_desc: str, product_analysis: dict = None) -> bool:
    hay = " ".join([
        product_desc or "",
        (product_analysis or {}).get("product_name", ""),
        (product_analysis or {}).get("appearance_summary", ""),
        " ".join((product_analysis or {}).get("key_features", []) or []),
    ]).lower()
    keys = ["cooler", "phone cooler", "radiator", "fan", "??", "??", "??", "?????", "?????"]
    return any(k.lower() in hay for k in keys)


def _u(text: str) -> str:
    return text.encode("ascii").decode("unicode_escape")


def _ozon_phone_cooler_strategy(product_desc: str = "", product_analysis: dict = None) -> dict:
    product_name = "Piva B3Pro"
    analysis_name = (product_analysis or {}).get("product_name", "")
    if analysis_name and "piva" in analysis_name.lower():
        product_name = analysis_name

    def U(s: str) -> str:
        return _u(s)

    base_style = (
        "Premium Ozon/Wildberries 3:4 vertical product card, high-end 3D commercial render, "
        "graphite black studio background, cold cyan rim light, controlled RGB accents, realistic metal and matte plastic, "
        "clean hierarchy, integrated Russian typography, no pasted overlay, no clutter, no random icons. "
        "Use the exact reference product: circular magnetic phone cooler, grille, RGB ring, vents, colors and proportions preserved. "
        "The product must be the dominant visual proof."
    )

    def shot(i, angle_ru, angle_cn, headline_ru, headline_cn, big, bullets_ru, bullets_cn, desc_ru, desc_cn, notes_cn, effect):
        headline = U(headline_ru)
        desc = U(desc_ru)
        bullets = [U(x) for x in bullets_ru]
        exact_text = ", ".join([headline, big, desc] + bullets[:2])
        return {
            "id": i,
            "angle_name_ru": U(angle_ru),
            "angle_name_cn": U(angle_cn),
            "headline_ru": headline,
            "headline_cn": U(headline_cn),
            "big_number": big,
            "bullets_ru": bullets,
            "bullets_cn": [U(x) for x in bullets_cn],
            "desc_ru": desc,
            "desc_cn": U(desc_cn),
            "layout_notes_cn": U(notes_cn),
            "img_prompt": (
                f"{base_style} Exact visible Russian text to integrate naturally into the image: {exact_text}. "
                f"Composition/effect: {effect}. Product must remain large, accurate, and undistorted. "
                "One selling point only, one visual proof only, no dense text wall, no cheap poster collage."
            ),
        }

    shots = [
        shot(1, r"1. \u0413\u043b\u0430\u0432\u043d\u043e\u0435 \u0444\u043e\u0442\u043e", r"1. \u4e3b\u56fe",
             r"\u041c\u0410\u0413\u041d\u0418\u0422\u041d\u042b\u0419 \u041a\u0423\u041b\u0415\u0420 PIVA B3PRO", r"Piva B3Pro \u78c1\u5438\u624b\u673a\u6563\u70ed\u5668", "25W",
             [r"-19.5\u00b0C \u043e\u0445\u043b\u0430\u0436\u0434\u0435\u043d\u0438\u0435", r"AI \u043a\u043e\u043d\u0442\u0440\u043e\u043b\u044c", r"RGB \u043f\u043e\u0434\u0441\u0432\u0435\u0442\u043a\u0430"],
             [r"\u5cf0\u503c\u964d\u6e29 -19.5\u00b0C", r"AI \u667a\u80fd\u63a7\u6e29", r"RGB \u706f\u5149"],
             r"\u041c\u043e\u0449\u043d\u043e\u0435 \u043e\u0445\u043b\u0430\u0436\u0434\u0435\u043d\u0438\u0435 \u0434\u043b\u044f \u0438\u0433\u0440", r"\u4e3b\u56fe\u53ea\u8d1f\u8d23\u4e00\u773c\u770b\u61c2\u4ea7\u54c1\u548c\u6838\u5fc3\u89c4\u683c",
             r"\u4ea7\u54c1\u5360\u753b\u9762\u7ea6 65%\uff0c\u4e0a\u65b9\u5927\u6807\u9898\uff0c\u4e09\u4e2a\u89c4\u683c\u505a\u5c0f\u6807\u7b7e\uff0c\u4e0d\u8981\u53c2\u6570\u5899",
             "Main hero image. Product large in center, slightly low 45 degree angle. Use one subtle cooling halo only."),
        shot(2, r"2. \u0420\u0435\u0437\u0443\u043b\u044c\u0442\u0430\u0442 \u043e\u0445\u043b\u0430\u0436\u0434\u0435\u043d\u0438\u044f", r"2. \u5f3a\u6548\u964d\u6e29",
             r"\u041e\u0425\u041b\u0410\u0416\u0414\u0415\u041d\u0418\u0415 \u0414\u041e -19.5\u00b0C", r"\u5cf0\u503c\u964d\u6e29 -19.5\u00b0C", "-19.5\u00b0C",
             [r"\u0425\u043e\u043b\u043e\u0434\u043d\u0430\u044f \u0437\u043e\u043d\u0430 \u0437\u0430 \u0441\u0435\u043a\u0443\u043d\u0434\u044b"], [r"\u5feb\u901f\u5f62\u6210\u4f4e\u6e29\u51b7\u533a"],
             r"\u0422\u0435\u043f\u043b\u043e \u0443\u0445\u043e\u0434\u0438\u0442 \u043e\u0442 \u0442\u0435\u043b\u0435\u0444\u043e\u043d\u0430", r"\u7528\u70ed\u5230\u51b7\u7684\u89c6\u89c9\u8bc1\u660e\u964d\u6e29\u7ed3\u679c",
             r"\u7ea2\u6a59\u5230\u51b0\u84dd\u70ed\u6210\u50cf\u6e10\u53d8\uff0c\u4ea7\u54c1\u548c\u624b\u673a\u80cc\u90e8\u4e3a\u4e3b",
             "Cooler attached to phone back; hot red/orange surface turning ice blue around cooler. One primary effect: thermal gradient plus faint frost."),
        shot(3, r"3. \u0421\u0438\u0441\u0442\u0435\u043c\u0430 \u043e\u0445\u043b\u0430\u0436\u0434\u0435\u043d\u0438\u044f", r"3. \u534a\u5bfc\u4f53\u5236\u51b7\u539f\u7406",
             r"\u041f\u041e\u041b\u0423\u041f\u0420\u041e\u0412\u041e\u0414\u041d\u0418\u041a\u041e\u0412\u041e\u0415 \u041e\u0425\u041b\u0410\u0416\u0414\u0415\u041d\u0418\u0415", r"\u534a\u5bfc\u4f53\u5236\u51b7\u6280\u672f", "TEC",
             [r"\u0425\u043e\u043b\u043e\u0434\u043d\u0430\u044f \u043f\u043b\u0430\u0441\u0442\u0438\u043d\u0430", r"\u0412\u043e\u0437\u0434\u0443\u0448\u043d\u044b\u0439 \u043f\u043e\u0442\u043e\u043a"], [r"\u5236\u51b7\u7247\u51b7\u7aef", r"\u98ce\u9053\u5e26\u8d70\u70ed\u91cf"],
             r"\u041f\u043b\u0430\u0441\u0442\u0438\u043d\u0430 \u043e\u0445\u043b\u0430\u0436\u0434\u0430\u0435\u0442, \u0432\u0435\u043d\u0442\u0438\u043b\u044f\u0442\u043e\u0440 \u0432\u044b\u0432\u043e\u0434\u0438\u0442 \u0442\u0435\u043f\u043b\u043e", r"\u89e3\u91ca\u4e3a\u4ec0\u4e48\u80fd\u964d\u6e29\uff1a\u5236\u51b7\u7247 + \u98ce\u6247\u98ce\u9053",
             r"\u53ef\u7528\u534a\u5256\u7ed3\u6784\u611f\uff0c\u4f46\u4e0d\u6539\u4ea7\u54c1\u5916\u5f62",
             "Semi-transparent technical cutaway while preserving the exact outer shape. Show cold plate, fan and airflow path. One effect: elegant cyan airflow arrows."),
        shot(4, r"4. \u041c\u043e\u0449\u043d\u043e\u0441\u0442\u044c 25W", r"4. 25W \u9ad8\u529f\u7387",
             r"\u041c\u041e\u0429\u041d\u041e\u0421\u0422\u042c \u041e\u0425\u041b\u0410\u0416\u0414\u0415\u041d\u0418\u042f 25W", r"25W \u6563\u70ed\u529f\u7387", "25W",
             [r"\u0411\u044b\u0441\u0442\u0440\u044b\u0439 \u043e\u0442\u0432\u043e\u0434 \u0442\u0435\u043f\u043b\u0430"], [r"\u9ad8\u529f\u7387\u5e26\u6765\u66f4\u5feb\u70ed\u91cf\u8f6c\u79fb"],
             r"\u0421\u0442\u0430\u0431\u0438\u043b\u044c\u043d\u0430\u044f \u0442\u0435\u043c\u043f\u0435\u0440\u0430\u0442\u0443\u0440\u0430 \u0432 \u0438\u0433\u0440\u0430\u0445", r"\u7528\u80fd\u91cf\u6838\u5fc3\u548c\u6563\u70ed\u8def\u5f84\u8bc1\u660e 25W",
             r"\u4ea7\u54c1\u4e2d\u5fc3\u514b\u5236\u80fd\u91cf\u5149\uff0c\u80cc\u666f\u9ad8\u7ea7\u6697\u8272",
             "Exact product floating in dark studio; controlled cyan energy core glow through grille, side vents highlighted. One effect: concentrated power glow."),
        shot(5, r"5. \u0422\u0438\u0445\u0430\u044f \u0440\u0430\u0431\u043e\u0442\u0430", r"5. \u4f4e\u566a\u97f3\u8fd0\u884c",
             r"\u0422\u0418\u0425\u041e\u0415 \u041e\u0425\u041b\u0410\u0416\u0414\u0415\u041d\u0418\u0415", r"\u4f4e\u566a\u97f3\u6563\u70ed", "LOW NOISE",
             [r"\u0420\u043e\u0432\u043d\u044b\u0439 \u043f\u043e\u0442\u043e\u043a \u0432\u043e\u0437\u0434\u0443\u0445\u0430"], [r"\u7a33\u5b9a\u98ce\u9053\u964d\u4f4e\u566a\u97f3\u611f"],
             r"\u041e\u0445\u043b\u0430\u0436\u0434\u0430\u0435\u0442 \u0431\u0435\u0437 \u043b\u0438\u0448\u043d\u0435\u0433\u043e \u0448\u0443\u043c\u0430", r"\u7528\u5b89\u9759\u684c\u9762\u548c\u7ec6\u5c0f\u58f0\u6ce2\u8868\u73b0\u4f4e\u566a",
             r"\u753b\u9762\u5e72\u51c0\u5b89\u9759\uff0c\u4ea7\u54c1\u5728\u624b\u673a\u80cc\u9762\uff0c\u5fae\u5f31\u58f0\u6ce2\u5373\u53ef",
             "Calm premium desk setup, cooler attached to smartphone, tiny transparent sound-wave rings fading quickly. One effect: minimal sound wave visualization."),
        shot(6, r"6. \u041c\u0430\u0433\u043d\u0438\u0442\u043d\u043e\u0435 \u043a\u0440\u0435\u043f\u043b\u0435\u043d\u0438\u0435", r"6. \u78c1\u5438\u5b89\u88c5",
             r"\u041c\u0410\u0413\u041d\u0418\u0422\u041d\u041e\u0415 \u041a\u0420\u0415\u041f\u041b\u0415\u041d\u0418\u0415", r"\u78c1\u5438\u7a33\u5b9a\u5b89\u88c5", "MAGNET",
             [r"\u0411\u044b\u0441\u0442\u0440\u043e \u043f\u043e\u0441\u0442\u0430\u0432\u0438\u0442\u044c", r"\u041d\u0430\u0434\u0451\u0436\u043d\u043e \u0434\u0435\u0440\u0436\u0438\u0442\u0441\u044f"], [r"\u5feb\u901f\u5438\u9644", r"\u7a33\u5b9a\u4e0d\u79fb\u4f4d"],
             r"\u0422\u043e\u0447\u043d\u043e \u0444\u0438\u043a\u0441\u0438\u0440\u0443\u0435\u0442\u0441\u044f \u043d\u0430 \u0441\u043c\u0430\u0440\u0442\u0444\u043e\u043d\u0435", r"\u5c55\u793a\u624b\u673a\u80cc\u90e8\u5438\u9644\u548c\u78c1\u73af\u5bf9\u4f4d",
             r"\u4ea7\u54c1\u4e0e\u624b\u673a\u80cc\u9762\u5206\u79bb\u4e00\u70b9\uff0c\u78c1\u529b\u7ebf\u7b80\u6d01\u663e\u793a",
             "Show exact cooler aligning to the back of a gaming smartphone, small gap with elegant cyan magnetic field rings. One effect: magnetic alignment rings."),
        shot(7, r"7. \u0423\u043f\u0440\u0430\u0432\u043b\u0435\u043d\u0438\u0435 APP", r"7. APP \u4e0e AI \u63a7\u5236",
             r"\u0423\u041f\u0420\u0410\u0412\u041b\u0415\u041d\u0418\u0415 \u0427\u0415\u0420\u0415\u0417 APP", r"\u624b\u673a APP \u667a\u80fd\u63a7\u5236", "AI",
             [r"\u0420\u0435\u0436\u0438\u043c\u044b \u043c\u043e\u0449\u043d\u043e\u0441\u0442\u0438", r"AI \u043a\u043e\u043d\u0442\u0440\u043e\u043b\u044c"], [r"\u6548\u7387\u6a21\u5f0f\u53ef\u8c03", r"AI \u6e29\u63a7"],
             r"\u041c\u0435\u043d\u044f\u0439\u0442\u0435 \u0440\u0435\u0436\u0438\u043c\u044b \u0441\u043e \u0441\u043c\u0430\u0440\u0442\u0444\u043e\u043d\u0430", r"\u5c55\u793a\u624b\u673a\u63a7\u5236\u754c\u9762\u8054\u52a8\u4ea7\u54c1\u706f\u5149\u548c\u6548\u7387\u6a21\u5f0f",
             r"\u4e00\u4fa7\u51fa\u73b0\u5e72\u51c0\u624b\u673a UI\uff0c\u4ea7\u54c1\u4ecd\u7136\u6700\u5927",
             "Cooler attached to phone; beside it a clean futuristic app interface with mode slider, temperature control and RGB toggle. One effect: subtle UI glow connected to cooler RGB."),
        shot(8, r"8. RGB \u0434\u0438\u0437\u0430\u0439\u043d", r"8. RGB \u7535\u7ade\u8d28\u611f",
             r"RGB \u041f\u041e\u0414\u0421\u0412\u0415\u0422\u041a\u0410", r"RGB \u706f\u5149\u8bbe\u8ba1", "RGB",
             [r"\u0418\u0433\u0440\u043e\u0432\u043e\u0439 \u0441\u0442\u0438\u043b\u044c", r"\u041d\u0430\u0441\u0442\u0440\u043e\u0439\u043a\u0430 \u0441\u0432\u0435\u0442\u0430"], [r"\u7535\u7ade\u6c1b\u56f4", r"\u706f\u6548\u53ef\u8c03"],
             r"\u041f\u043e\u0434\u0441\u0432\u0435\u0442\u043a\u0430 \u0434\u043b\u044f \u0438\u0433\u0440\u043e\u0432\u043e\u0433\u043e \u0441\u0442\u0438\u043b\u044f", r"\u53ea\u5c55\u793a\u9ad8\u7ea7 RGB\uff0c\u4e0d\u8981\u5ec9\u4ef7\u9713\u8679",
             r"\u6697\u573a\u3001\u9ed1\u91d1\u5c5e\u3001RGB \u73af\u6e05\u695a\uff0c\u80cc\u666f\u514b\u5236",
             "Dark premium gaming atmosphere with exact cooler grille and RGB ring glowing cleanly, macro 45 degree close-up. One effect: controlled RGB light reflections."),
        shot(9, r"9. \u041a\u043e\u043c\u043f\u043b\u0435\u043a\u0442", r"9. \u6027\u80fd\u5957\u88c5\u4e0e\u4fe1\u4efb",
             r"\u041a\u041e\u041c\u041f\u041b\u0415\u041a\u0422 \u0414\u041b\u042f \u041c\u041e\u0429\u041d\u041e\u0421\u0422\u0418", r"\u6027\u80fd\u5957\u88c5", "45W",
             [r"GaN \u0437\u0430\u0440\u044f\u0434\u043a\u0430 45W", r"\u041a\u0443\u043b\u0435\u0440 Piva B3Pro"], [r"45W \u6c2e\u5316\u9553\u5145\u7535\u5934", r"Piva B3Pro \u6563\u70ed\u5668"],
             r"\u0413\u043e\u0442\u043e\u0432\u044b\u0439 \u043d\u0430\u0431\u043e\u0440 \u0434\u043b\u044f \u043e\u0445\u043b\u0430\u0436\u0434\u0435\u043d\u0438\u044f", r"\u7528\u5305\u88c5\u3001\u5145\u7535\u5934\u548c\u4ea7\u54c1\u5e73\u94fa\u63d0\u5347\u4fe1\u4efb\u548c\u4ef7\u503c\u611f",
             r"\u4ea7\u54c1\u300145W GaN\u3001\u5305\u88c5\u6574\u9f50\u9648\u5217\uff0c\u9ad8\u7ea7\u5546\u54c1\u6444\u5f71",
             "Exact cooler, 45W GaN charger and clean packaging arranged neatly like premium electronics retail photography. One effect: subtle cyan edge light."),
    ]
    return normalize_strategy_schema({
        "desire_core_ru": U(r"\u041f\u0440\u0435\u043c\u0438\u0430\u043b\u044c\u043d\u043e\u0435 \u043e\u0445\u043b\u0430\u0436\u0434\u0435\u043d\u0438\u0435 \u0441\u043c\u0430\u0440\u0442\u0444\u043e\u043d\u0430"),
        "desire_core_cn": U(r"\u9ad8\u7aef\u624b\u673a\u6563\u70ed\uff1a\u5f3a\u964d\u6e29\u3001AI\u63a7\u6e29\u3001RGB\u4e0e\u6027\u80fd\u5957\u88c5"),
        "shots": shots,
    })


def _enforce_ozon_phone_cooler_strategy(strategy: dict, product_desc: str, product_analysis: dict = None) -> dict:
    if not _is_phone_cooler_product(product_desc, product_analysis):
        return normalize_strategy_schema(strategy)
    # Use the local blueprint as the backbone. It is intentionally stricter than GPT output.
    return _ozon_phone_cooler_strategy(product_desc, product_analysis)


def generate_strategy(product_desc: str, platform: str, trigger: str,
                      style: str, ratio: str, images_base64: list = None,
                      product_analysis: dict = None) -> dict:
    """GPT-4o generates complete 9-shot strategy with PT-BR copy and image prompts."""
    key = _get_key()
    images_base64 = images_base64 or []

    platform_hint = PLATFORM_HINTS.get(platform, PLATFORM_HINTS["TikTok"])
    trigger_hint = TRIGGER_HINTS.get(trigger, TRIGGER_HINTS["Impulse"])
    style_hint = STYLE_PRESETS.get(style, STYLE_PRESETS["tiktok"])

    analysis_text = ""
    if product_analysis:
        analysis_text = (
            f"\nPRODUCT ANALYSIS — GROUND TRUTH (lock ALL visuals to these details):\n"
            f"Name: {product_analysis.get('product_name', '')}\n"
            f"Materials: {', '.join(product_analysis.get('materials', []))}\n"
            f"Colors: {', '.join(product_analysis.get('colors', []))}\n"
            f"Textures: {', '.join(product_analysis.get('textures', []))}\n"
            f"Shape: {product_analysis.get('shape', '')}\n"
            f"Key Features: {', '.join(product_analysis.get('key_features', []))}\n"
            f"Branding: {', '.join(product_analysis.get('branding_elements', []))}\n"
            f"Visual Hook: {product_analysis.get('visual_hook', '')}\n"
            f"Best Angles: {', '.join(product_analysis.get('photography_angles', []))}\n"
        )

    user_parts = []
    for img in images_base64[:5]:
        user_parts.append({"type": "image_url", "image_url": {"url": f"data:{img['mime']};base64,{img['data']}"}})

    user_parts.append({"type": "text", "text": f"""Product: {product_desc}
Platform: {platform} — {platform_hint}
Trigger: {trigger} — {trigger_hint}
Visual Style: {style} — {style_hint}
Ratio: {ratio}
{analysis_text}
Reference images attached — lock product appearance exactly as shown.
Generate the conversion strategy."""})

    is_ozon = platform.lower() == "ozon"
    system_prompt = OZON_STRATEGY_SYSTEM if is_ozon else STRATEGY_SYSTEM
    max_tok = 6000 if is_ozon else 4000

    try:
        resp = _session.post(_chat_endpoint(),
            headers={"Authorization": f"Bearer {key}", "Content-Type": "application/json"},
            json={
                "model": "gpt-4o",
                "messages": [
                    {"role": "system", "content": system_prompt},
                    {"role": "user", "content": user_parts},
                ],
                "temperature": 0.45 if is_ozon else 0.8,
                "max_tokens": max_tok,
            }, timeout=120)
        resp.raise_for_status()
        raw = resp.json()["choices"][0]["message"]["content"].strip()
        if raw.startswith("```"): raw = raw.split("\n", 1)[1]
        if raw.endswith("```"): raw = raw[:-3]
        strategy = normalize_strategy_schema(json.loads(raw))
    except Exception:
        if is_ozon and _is_phone_cooler_product(product_desc, product_analysis):
            log.warning("Ozon strategy API failed; using local phone-cooler blueprint", exc_info=True)
            return _ozon_phone_cooler_strategy(product_desc, product_analysis)
        raise

    if is_ozon:
        return _enforce_ozon_phone_cooler_strategy(strategy, product_desc, product_analysis)
    return strategy


# ====== Product Analysis (GPT-4o) ======
PRODUCT_ANALYSIS_SYSTEM = """You are an elite product photographer. Analyze the provided product images in extreme visual detail. Be PRECISE — your analysis will be used to generate new images that must match the original product exactly.

Return ONLY valid JSON:
{
  "product_name": "What is this product called?",
  "appearance_summary": "Dense 1-sentence visual description of the EXACT product: shape, color, material, size, key visual elements, branding. This will be used to anchor image generation.",
  "materials": ["exact materials visible in the images"],
  "colors": ["primary color", "secondary color", "accent color"],
  "textures": ["matte metal", "glossy plastic", "rubber grip", etc.],
  "shape": "Precise form factor: round/rectangular/cylindrical, size relative to hand, proportions",
  "key_features": ["visible feature 1", "visible feature 2", "visible feature 3"],
  "branding_elements": ["logo description and placement", "brand colors", "text on product"],
  "visual_hook": "The single most eye-catching visual element",
  "photography_angles": ["best product angle", "best detail angle"],
  "deformation_warning": "What the image generator SHOULD NOT do: e.g. 'do not stretch the product', 'do not change the proportions', 'keep the circular shape intact'. For radiator/fan/cooler products specifically: preserve exact fin count and spacing, preserve fan blade count and shape, do not rearrange heat pipe layout, keep ARGB/LED ring position accurate, do not stretch or compress product aspect ratio, copper/aluminum reflections must look real — not plastic."
}"""


def analyze_product(product_desc: str, images_base64: list) -> dict:
    """GPT-4o analyzes product images in detail. Returns structured visual analysis."""
    key = _get_key()
    images_base64 = images_base64 or []
    user_content = []
    for img in images_base64[:5]:
        user_content.append({
            "type": "image_url",
            "image_url": {"url": f"data:{img['mime']};base64,{img['data']}"}
        })
    user_content.append({
        "type": "text",
        "text": f"Analyze these product images in extreme visual detail. Additional context: {product_desc}"
    })
    resp = _session.post(_chat_endpoint(),
        headers={"Authorization": f"Bearer {key}", "Content-Type": "application/json"},
        json={
            "model": "gpt-4o",
            "messages": [
                {"role": "system", "content": PRODUCT_ANALYSIS_SYSTEM},
                {"role": "user", "content": user_content},
            ],
            "temperature": 0.3, "max_tokens": 2000,
        }, timeout=90)
    resp.raise_for_status()
    raw = resp.json()["choices"][0]["message"]["content"].strip()
    if raw.startswith("```"): raw = raw.split("\n", 1)[1]
    if raw.endswith("```"): raw = raw[:-3]
    return normalize_strategy_schema(json.loads(raw))




def _font(size: int, bold: bool = False):
    try:
        from PIL import ImageFont
        candidates = [
            r"C:\Windows\Fonts\arialbd.ttf" if bold else r"C:\Windows\Fonts\arial.ttf",
            r"C:\Windows\Fonts\seguisb.ttf" if bold else r"C:\Windows\Fonts\segoeui.ttf",
            r"C:\Windows\Fonts\calibrib.ttf" if bold else r"C:\Windows\Fonts\calibri.ttf",
        ]
        for fp in candidates:
            if Path(fp).exists():
                return ImageFont.truetype(fp, size=size)
        return ImageFont.load_default()
    except Exception:
        return None


def _text_size(draw, text, font):
    try:
        box = draw.textbbox((0, 0), text, font=font)
        return box[2] - box[0], box[3] - box[1]
    except Exception:
        return (len(text) * 10, 20)


def _glass_rect(draw, xy, radius=18, fill=(8, 14, 22, 172), outline=(0, 207, 255, 150)):
    try:
        draw.rounded_rectangle(xy, radius=radius, fill=fill, outline=outline, width=2)
    except Exception:
        draw.rectangle(xy, fill=fill, outline=outline)


def _maybe_apply_text_fallback(path: Path, shot: dict) -> bool:
    """Add a small premium HUD text badge as a fallback, not full layout."""
    mode = os.getenv("TEXT_FALLBACK_MODE", "auto").lower().strip()
    if mode in ("0", "false", "off", "none"):
        return False
    headline = (shot.get("headline") or shot.get("headline_ru") or "").strip()
    big = (shot.get("big_number") or _extract_big_number(shot) or "").strip()
    bullets = shot.get("bullets") or shot.get("bullets_ru") or []
    if not headline and not big:
        return False
    try:
        from PIL import Image, ImageDraw, ImageFilter
        img = Image.open(path).convert("RGBA")
        w, h = img.size
        overlay = Image.new("RGBA", img.size, (0, 0, 0, 0))
        draw = ImageDraw.Draw(overlay)

        title_font = _font(max(32, int(w * 0.055)), bold=True)
        num_font = _font(max(46, int(w * 0.085)), bold=True)
        small_font = _font(max(20, int(w * 0.028)), bold=False)

        # Top premium chip: brand + headline. Keep it compact so AI design remains dominant.
        pad = int(w * 0.035)
        x1, y1 = pad, pad
        max_box_w = int(w * 0.78)
        title = headline[:34]
        tw, th = _text_size(draw, title, title_font)
        bw = min(max_box_w, max(tw + pad * 2, int(w * 0.38)))
        bh = int(h * 0.105)
        _glass_rect(draw, (x1, y1, x1 + bw, y1 + bh), radius=18)
        draw.text((x1 + pad, y1 + int(bh * 0.18)), title, font=title_font, fill=(255, 255, 255, 245))

        if big:
            num = big[:16]
            nw, nh = _text_size(draw, num, num_font)
            bx2 = w - pad
            by2 = int(h * 0.18)
            bx1 = max(pad, bx2 - nw - pad * 2)
            by1 = by2 - nh - int(pad * 1.3)
            _glass_rect(draw, (bx1, by1, bx2, by2), radius=20, fill=(0, 40, 66, 164), outline=(0, 207, 255, 180))
            draw.text((bx1 + pad, by1 + int(pad * 0.35)), num, font=num_font, fill=(0, 207, 255, 255))

        short_bullets = [str(b)[:28] for b in bullets[:2] if str(b).strip()]
        if short_bullets:
            y = h - pad - len(short_bullets) * int(h * 0.04) - pad
            box_h = len(short_bullets) * int(h * 0.045) + pad
            _glass_rect(draw, (pad, y, int(w * 0.62), y + box_h), radius=16, fill=(10, 10, 14, 135), outline=(255, 215, 0, 120))
            yy = y + int(pad * 0.6)
            for b in short_bullets:
                draw.text((pad * 2, yy), b, font=small_font, fill=(255, 215, 0, 238))
                yy += int(h * 0.045)

        merged = Image.alpha_composite(img, overlay).convert("RGB")
        merged.save(path, quality=96)
        return True
    except Exception as e:
        log.warning(f"Text fallback failed for {path}: {e}")
        return False

# ====== GPT Image-2 Rendering (via TikHub) ======
def render_shot(img_prompt: str, size: str = "1024x1024",
                reference_images: list = None,
                product_analysis: dict = None,
                ratio_key: str = "1:1") -> str:
    """Render one shot via gpt-image-2 with product reference images. Returns image URL."""
    key = _get_key()
    model = _image_model()
    reference_images = reference_images or []

    content_parts = []
    for img in reference_images[:3]:
        content_parts.append({
            "type": "image_url",
            "image_url": {"url": f"data:{img['mime']};base64,{img['data']}"}
        })

    analysis_hint = ""
    if product_analysis:
        analysis_hint = (
            f"CRITICAL PRODUCT IDENTITY: {product_analysis.get('appearance_summary', '')} "
            f"DEFORMATION WARNING: {product_analysis.get('deformation_warning', 'do not distort the product shape')}. "
            f"Materials: {', '.join(product_analysis.get('materials', []))}. "
            f"Colors: {', '.join(product_analysis.get('colors', []))}. "
            f"Shape: {product_analysis.get('shape', '')}. "
        )

    ratio_context = RATIO_CONTEXT.get(ratio_key, "Standard product photography")
    prompt_text = (
        f"Create one coherent high-end e-commerce product advertisement, not a collage. "
        f"Use the EXACT product from the reference images as the hero object; preserve its circular body, grille, RGB ring, vents, colors, proportions, materials, and branding. "
        f"Do not invent a different product, do not warp the circle, do not stretch the grille, do not add unrelated accessories unless the shot explicitly asks for the kit. "
        f"The product must occupy the dominant visual area and look like premium 3D commercial photography. "
        f"All Russian typography must be generated naturally inside the image design, integrated with lighting, perspective, reflections, or panel surfaces. Do not leave empty text boxes and do not create pasted overlay text. "
        f"Keep the composition clean: one main selling point, one big number if provided, one short explanation, restrained premium details. "
        f"Avoid clutter, random icons, dense spec walls, cheap poster styling, extra products, deformed phone coolers, messy HUD spam. "
        f"Shot brief: {img_prompt}. "
        f"{analysis_hint}"
        f"Style: luxury consumer electronics, graphite black studio, cold cyan rim light, subtle RGB accents, realistic metal/plastic textures, sharp focus, cinematic depth, premium Ozon/Wildberries tech listing. "
        f"Format: {ratio_context}. Output as {size}."
    )
    content_parts.append({"type": "text", "text": prompt_text})

    resp = _session.post(_chat_endpoint(),
        headers={"Authorization": f"Bearer {key}", "Content-Type": "application/json"},
        json={
            "model": model,
            "messages": [{"role": "user", "content": content_parts}],
            "max_tokens": 4000,
        }, timeout=180)
    resp.raise_for_status()
    content = resp.json()["choices"][0]["message"]["content"]
    urls = re.findall(r'https?://[^\s)\]]+', content)
    if not urls:
        raise RuntimeError(f"No image URL in response: {content[:200]}")
    return urls[0]


def _render_and_save(args: tuple) -> dict:
    shot, size, out_dir, idx, ref_images, product_analysis, ratio_key = args
    try:
        url = render_shot(shot["img_prompt"], size,
                         reference_images=ref_images,
                         product_analysis=product_analysis,
                         ratio_key=ratio_key)
        r = _download_with_retry(url, timeout=60)
        raw = re.sub(r'[^a-zA-Z0-9\-\s]', '', shot.get('angle_name', f'shot{idx+1}'))
        raw = re.sub(r'\s+', '_', raw.strip())[:40]
        fname = f"{idx+1:02d}_{raw}.png" if raw else f"{idx+1:02d}_shot.png"
        path = out_dir / fname
        path.write_bytes(r.content)
        return {**shot, "image_url": url, "saved_path": fname, "text_fallback": False}
    except Exception as e:
        return {**shot, "error": str(e)}


def render_all_shots(strategy: dict, ratio: str, pid: str,
                     reference_images: list = None,
                     product_analysis: dict = None,
                     progress_callback=None) -> list:
    """Render shots from strategy. Passes reference images for product fidelity."""
    out_dir = IMAGE_OUTPUT_DIR / pid
    out_dir.mkdir(parents=True, exist_ok=True)
    size = ASPECT_RATIOS.get(ratio, "1024x1024")
    shots = strategy.get("shots", [])

    tasks = [(s, size, out_dir, i, reference_images, product_analysis, ratio) for i, s in enumerate(shots)]
    results = []
    if not tasks:
        return results
    n_workers = max(1, min(int(os.getenv("RENDER_WORKERS", "3")), len(tasks)))
    done = 0
    total = len(tasks)
    with ThreadPoolExecutor(max_workers=n_workers) as ex:
        futures = [ex.submit(_render_and_save, t) for t in tasks]
        for f in as_completed(futures):
            try:
                results.append(f.result())
            except Exception as e:
                results.append({"error": str(e)})
            done += 1
            if progress_callback:
                try:
                    progress_callback(done, total)
                except Exception:
                    pass
    results.sort(key=lambda r: r.get("id", 99))
    return results


# ====== Compliance Check ======
COMPLIANCE_RISK_WORDS = re.compile(
    r'cura|tratamento|medicamento|milagre|garantido|100%|melhor.do.mundo|'
    r'sem.efeitos.colaterais|resultados.garantidos|aprovação.anvisa|'
    r'promoção|oferta|grátis|só.hoje|imperdível|últimas.unidades',
    re.IGNORECASE
)

def check_compliance(text: str) -> dict:
    if COMPLIANCE_RISK_WORDS.search(text):
        return {"label": "ALERTA: Termo de Risco", "level": "high"}
    return {"label": "Conformidade Comercial", "level": "safe"}


# ====== State ======
def load_states(): return json.loads(STATES_FILE.read_text(encoding="utf-8")) if STATES_FILE.exists() else {"projects": {}}
def save_states(s):
    # Backup before overwrite
    if STATES_FILE.exists():
        bak = STATES_FILE.with_suffix('.json.bak')
        try: bak.write_text(STATES_FILE.read_text(encoding='utf-8'), encoding='utf-8')
        except: pass
    STATES_FILE.write_text(json.dumps(s, ensure_ascii=False, indent=2), encoding='utf-8')
def init_dirs():
    IMAGE_INPUT_DIR.mkdir(parents=True, exist_ok=True)
    IMAGE_OUTPUT_DIR.mkdir(parents=True, exist_ok=True)
