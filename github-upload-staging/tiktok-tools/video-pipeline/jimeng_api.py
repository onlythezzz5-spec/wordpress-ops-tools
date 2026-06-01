#!/usr/bin/env python3
"""
Jimeng (即梦) Video Generation API via Volcano Engine (火山方舟).

Auth: IAM v4 signing (HMAC-SHA256)
Models:
  - jimeng_i2v_first_v30_1080  → 1080P image-to-video (首帧), 5s/10s
  - jimeng_i2v_first_v30       → 720P image-to-video (首帧), 5s/10s
  - doubao-seedance-1.5-pro    → Pro, 4~15s, 1080P
"""

import hashlib, hmac, json, os, time, logging, base64
from datetime import datetime, timezone
from pathlib import Path
from urllib.parse import quote

import requests

log = logging.getLogger("jimeng")

REGION = "cn-north-1"
SERVICE = "cv"
HOST = "visual.volcengineapi.com"
ENDPOINT = f"https://{HOST}"
API_VERSION = "2022-08-31"

# Model name → req_key mapping
MODELS = {
    "jimeng-i2v-1080": {
        "req_key": "jimeng_i2v_first_v30_1080",
        "name": "即梦 3.0 Pro 1080P",
        "max_duration": 10, "min_duration": 5,
    },
    "jimeng-i2v-720": {
        "req_key": "jimeng_i2v_first_v30",
        "name": "即梦 3.0 720P",
        "max_duration": 10, "min_duration": 5,
    },
    "seedance-1.5-pro": {
        "req_key": "doubao-seedance-1.5-pro-251215",
        "name": "Seedance 1.5 Pro",
        "max_duration": 15, "min_duration": 4,
    },
}


def _sign(key: bytes, msg: str) -> bytes:
    return hmac.new(key, msg.encode("utf-8"), hashlib.sha256).digest()


def _sha256_hex(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def _get_credentials():
    ak = os.getenv("VOLCANO_ACCESS_KEY", "")
    sk = os.getenv("VOLCANO_SECRET_KEY", "")
    if not ak or not sk:
        raise RuntimeError("请在 .env 中设置 VOLCANO_ACCESS_KEY 和 VOLCANO_SECRET_KEY（火山方舟控制台获取）")
    return ak, sk


def _sign_request(method: str, uri: str, query: str, headers: dict, payload: bytes,
                  ak: str, sk: str) -> dict:
    """IAM v4 signing for Volcano Engine."""
    now = datetime.now(timezone.utc)
    date_short = now.strftime("%Y%m%d")
    datetime_str = now.strftime("%Y%m%dT%H%M%SZ")
    service = SERVICE
    region = REGION

    # Step 1: Canonical request
    canonical_headers = "\n".join(f"{k}:{v}" for k, v in sorted(headers.items())) + "\n"
    signed_headers = ";".join(sorted(headers.keys()))
    canonical_request = f"{method}\n{uri}\n{query}\n{canonical_headers}\n{signed_headers}\n{_sha256_hex(payload)}"

    # Step 2: String to sign
    credential_scope = f"{date_short}/{region}/{service}/request"
    string_to_sign = f"HMAC-SHA256\n{datetime_str}\n{credential_scope}\n{_sha256_hex(canonical_request.encode('utf-8'))}"

    # Step 3: Signature
    k_date = _sign(("VOLC" + sk).encode("utf-8"), date_short)
    k_region = _sign(k_date, region)
    k_service = _sign(k_region, service)
    k_signing = _sign(k_service, "request")
    signature = hmac.new(k_signing, string_to_sign.encode("utf-8"), hashlib.sha256).hexdigest()

    # Step 4: Authorization header
    authorization = (f"HMAC-SHA256 Credential={ak}/{credential_scope}, "
                     f"SignedHeaders={signed_headers}, Signature={signature}")

    return {
        "Host": HOST,
        "X-Date": datetime_str,
        "Authorization": authorization,
        "Content-Type": "application/json",
    }


def _api_call(action: str, body: dict, timeout: int = 30) -> dict:
    """Make a signed API call to Volcano Engine."""
    ak, sk = _get_credentials()
    payload = json.dumps(body).encode("utf-8")
    uri = "/"
    query = f"Action={action}&Version={API_VERSION}"

    headers = _sign_request("POST", uri, query, {"host": HOST}, payload, ak, sk)

    resp = requests.post(f"{ENDPOINT}/?{query}", headers=headers, data=payload, timeout=timeout)
    resp.raise_for_status()
    result = resp.json()
    if "ResponseMetadata" in result and "Error" in result.get("ResponseMetadata", {}):
        err = result["ResponseMetadata"]["Error"]
        raise RuntimeError(f"Jimeng API error: {err.get('Code')} - {err.get('Message')}")
    return result


def image_to_video(image_path: str, prompt: str = "", duration: int = 5,
                   model_id: str = "jimeng-i2v-1080") -> dict:
    """Submit image-to-video task. Returns {'task_id': '...'}"""
    model = MODELS.get(model_id, MODELS["jimeng-i2v-1080"])
    dur = max(model["min_duration"], min(duration, model["max_duration"]))
    # Round to 5 or 10
    if dur <= 6:
        dur = 5
    else:
        dur = 10
    frames = 24 * dur + 1

    # Read image as base64
    with open(image_path, "rb") as f:
        img_b64 = base64.b64encode(f.read()).decode("utf-8")

    body = {
        "req_key": model["req_key"],
        "binary_data_base64": [img_b64],
        "prompt": prompt or "smooth cinematic product shot, professional lighting, 4K",
        "frames": frames,
        "seed": -1,
    }

    result = _api_call("CVSync2AsyncSubmitTask", body)
    task_id = result.get("data", {}).get("task_id", "")
    if not task_id:
        raise RuntimeError(f"No task_id in response: {json.dumps(result, ensure_ascii=False)[:300]}")
    log.info(f"Jimeng task submitted: {task_id} ({model['name']}, {dur}s)")
    return {"task_id": task_id, "model": model_id}


def poll_task(task_id: str, timeout_minutes: int = 15) -> str:
    """Poll until done. Returns video download URL."""
    deadline = time.time() + timeout_minutes * 60
    while time.time() < deadline:
        result = _api_call("CVSync2AsyncGetResult", {"req_key": "jimeng_i2v_first_v30_1080", "task_id": task_id})
        data = result.get("data", {})
        status = data.get("status", "unknown")
        if status == "done":
            video_url = data.get("video_url", "")
            if video_url:
                return video_url
            raise RuntimeError("Task done but no video_url")
        if status in ("not_found", "expired", "failed"):
            raise RuntimeError(f"Jimeng task {status}: {data.get('message','')}")
        time.sleep(5)
    raise TimeoutError(f"Jimeng task {task_id} timeout")


# ====== Cost estimate ======
def estimate_cost(model_id: str, duration: int, count: int) -> dict:
    """Rough cost estimate (RMB)."""
    prices = {
        "jimeng-i2v-1080": 0.3,  # per second
        "jimeng-i2v-720": 0.15,
        "seedance-1.5-pro": 0.5,
    }
    per_sec = prices.get(model_id, 0.3)
    total_sec = duration * count
    return {"per_second": per_sec, "total_seconds": total_sec, "estimate_rmb": round(per_sec * total_sec, 2)}
