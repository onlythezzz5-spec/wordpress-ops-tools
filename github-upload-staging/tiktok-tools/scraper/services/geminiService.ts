
import { GoogleGenAI, Type } from "@google/genai";
import { Language } from "../types";

const cleanJson = (text: string) => {
  return text.replace(/```json/g, "").replace(/```/g, "").trim();
};

export const huntWinningProducts = async (lang: Language = 'es') => {
  const ai = new GoogleGenAI({ apiKey: process.env.API_KEY });
  const response = await ai.models.generateContent({
    model: 'gemini-3-flash-preview',
    contents: `[INVESTIGACIÓN DE MERCADO REAL ENERO 2026] 
    Utiliza Google Search para identificar los 50 productos con mayor tendencia de ventas esta semana en TikTok Shop, Amazon y marketplaces globales.
    
    REGLAS PARA LOS ENLACES (CRÍTICO):
    1. SOURCE_URL: Debe ser un enlace real a una tienda o artículo de noticias verificado. Si no encuentras el enlace directo al producto, pon el enlace del marketplace o la noticia que lo menciona.
    2. IMAGE_URL: Intenta obtener una URL de imagen directa. Si no estás 100% seguro de que la URL es una imagen pública (.jpg, .png), deja el campo vacío o usa "placeholder".
    3. NO INVENTES ENLACES: Es preferible un string vacío que un enlace que no funciona.
    4. Los nombres de los productos deben ser específicos (Marca + Modelo).`,
    config: {
      tools: [{ googleSearch: {} }],
      responseMimeType: "application/json",
      responseSchema: {
        type: Type.ARRAY,
        items: {
          type: Type.OBJECT,
          properties: {
            name: { type: Type.STRING },
            niche: { type: Type.STRING },
            priceEstimate: { type: Type.STRING },
            reasonWhyWinning: { type: Type.STRING },
            potentialMargin: { type: Type.STRING },
            trendScore: { type: Type.NUMBER },
            imageUrl: { type: Type.STRING },
            sourceUrl: { type: Type.STRING },
            sourceTitle: { type: Type.STRING }
          },
          required: ["name", "niche", "priceEstimate", "reasonWhyWinning", "potentialMargin", "trendScore", "imageUrl", "sourceUrl", "sourceTitle"]
        }
      }
    }
  });
  return JSON.parse(cleanJson(response.text || "[]"));
};

export const getDeepProductAnalysis = async (productName: string, lang: Language = 'es') => {
  const ai = new GoogleGenAI({ apiKey: process.env.API_KEY });
  const response = await ai.models.generateContent({
    model: 'gemini-3-flash-preview',
    contents: `[ANÁLISIS DE COMPETENCIA ENERO 2026] 
    Investiga a fondo el producto: "${productName}". 
    Encuentra enlaces reales de competidores y fuentes de noticias. 
    Asegúrate de que las URLs en 'sources' sean funcionales.`,
    config: {
      tools: [{ googleSearch: {} }],
      responseMimeType: "application/json",
      responseSchema: {
        type: Type.OBJECT,
        properties: {
          competitors: { type: Type.ARRAY, items: { type: Type.STRING } },
          customerSentiment: { type: Type.STRING },
          topRisks: { type: Type.ARRAY, items: { type: Type.STRING } },
          sources: { 
            type: Type.ARRAY, 
            items: { 
              type: Type.OBJECT, 
              properties: { 
                title: { type: Type.STRING }, 
                uri: { type: Type.STRING } 
              } 
            } 
          }
        },
        required: ["competitors", "customerSentiment", "topRisks", "sources"]
      }
    }
  });
  return JSON.parse(cleanJson(response.text || "{}"));
};

export const analyzeNicheMarket = async (niche: string, lang: Language = 'es') => {
  const ai = new GoogleGenAI({ apiKey: process.env.API_KEY });
  const response = await ai.models.generateContent({
    model: 'gemini-3-flash-preview',
    contents: `Analiza la estructura competitiva del nicho "${niche}" en Enero 2026 usando Google Search.`,
    config: {
      tools: [{ googleSearch: {} }],
      responseMimeType: "application/json",
      responseSchema: {
        type: Type.OBJECT,
        properties: {
          brands: { 
            type: Type.ARRAY, 
            items: { 
              type: Type.OBJECT, 
              properties: { 
                name: { type: Type.STRING }, 
                sharePercent: { type: Type.NUMBER } 
              } 
            } 
          },
          giniIndex: { type: Type.NUMBER },
          insight: { type: Type.STRING }
        },
        required: ["brands", "giniIndex", "insight"]
      }
    }
  });
  return JSON.parse(cleanJson(response.text || "{}"));
};

export const analyzeMarketTrends = async (query: string) => {
  const ai = new GoogleGenAI({ apiKey: process.env.API_KEY });
  const response = await ai.models.generateContent({
    model: 'gemini-3-flash-preview',
    contents: `Resumen ejecutivo basado en noticias de esta semana (Enero 2026) para: "${query}".`,
    config: { tools: [{ googleSearch: {} }] }
  });
  return response.text || "";
};

export const generateProductDescription = async (productInfo: string, tone: string, audience: string) => {
  const ai = new GoogleGenAI({ apiKey: process.env.API_KEY });
  const response = await ai.models.generateContent({
    model: 'gemini-3-pro-preview',
    contents: `Crea un copy de venta agresivo para: ${productInfo}. Tono: ${tone}. Audiencia: ${audience}.`,
  });
  return response.text || "";
};
