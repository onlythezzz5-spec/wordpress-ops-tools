
import React, { useState, useEffect } from 'react';
import { analyzeNicheMarket } from '../services/geminiService';
import { Language } from '../types';
import { translations } from '../translations';

interface AnalysisProps {
  niche?: string;
  lang: Language;
}

const Analysis: React.FC<AnalysisProps> = ({ niche = "E-commerce 2026", lang }) => {
  const t = translations[lang];
  const [data, setData] = useState<any>(null);
  const [loading, setLoading] = useState(false);

  useEffect(() => {
    const fetchNicheAnalysis = async () => {
      setLoading(true);
      try {
        const result = await analyzeNicheMarket(niche, lang);
        setData(result);
      } catch (error) {
        console.error("Niche analysis failed", error);
      } finally {
        setLoading(false);
      }
    };
    fetchNicheAnalysis();
  }, [niche, lang]);

  return (
    <div className="p-8 space-y-8 max-w-[1600px] mx-auto animate-in fade-in duration-700">
      <div className="flex flex-col gap-1">
        <h2 className="text-3xl font-bold text-white font-display tracking-tight flex items-center gap-3">
          {t.marketConcentration}: <span className="text-primary">{niche}</span>
          {loading && <span className="size-5 border-2 border-primary/20 border-t-primary rounded-full animate-spin"></span>}
        </h2>
        <p className="text-text-secondary">Análisis real de líderes de mercado y dispersión de ingresos (Gini Index) basado en búsqueda en vivo 2026.</p>
      </div>

      <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
        {[
          { label: 'Gini Index', value: data?.giniIndex?.toFixed(2) || '0.00', hint: data?.giniIndex > 0.6 ? 'Alta Concentración' : 'Mercado Fragmentado', icon: 'scatter_plot' },
          { label: 'Total Players', value: data?.brands?.length || '5+', hint: 'Principales jugadores', icon: 'domain' },
          { label: 'Oportunidad', value: data?.giniIndex < 0.5 ? 'Alta' : 'Moderada', hint: 'Basado en barreras', icon: 'lightbulb' }
        ].map((stat, i) => (
          <div key={i} className="bg-surface rounded-xl border border-border p-5 relative overflow-hidden group">
            <p className="text-text-secondary text-sm font-medium mb-1">{stat.label}</p>
            <h3 className="text-3xl font-bold text-white font-display">{stat.value}</h3>
            <p className="text-[10px] text-primary font-bold uppercase tracking-wider mt-2">{stat.hint}</p>
          </div>
        ))}
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-3 gap-8">
        <div className="lg:col-span-2 bg-surface rounded-2xl border border-border p-8">
          <h3 className="text-xl font-bold text-white mb-8">{t.brandsFound}</h3>
          <div className="space-y-6">
            {data?.brands?.map((brand: any, i: number) => (
              <div key={i} className="space-y-2">
                <div className="flex justify-between text-sm font-bold text-white">
                  <span>{brand.name}</span>
                  <span className="text-primary">{brand.sharePercent}%</span>
                </div>
                <div className="h-3 w-full bg-background rounded-full overflow-hidden border border-border">
                   <div 
                    className="h-full bg-gradient-to-r from-primary/50 to-primary transition-all duration-1000" 
                    style={{ width: `${brand.sharePercent}%` }}
                   ></div>
                </div>
              </div>
            )) || (
              <div className="space-y-4 animate-pulse">
                {Array(5).fill(0).map((_, i) => <div key={i} className="h-8 bg-background rounded-lg"></div>)}
              </div>
            )}
          </div>
        </div>

        <div className="bg-surface rounded-2xl border border-border p-8 flex flex-col">
           <h3 className="text-xl font-bold text-white mb-4 flex items-center gap-2">
             <span className="material-symbols-outlined text-primary">auto_awesome</span>
             IA Insight Analysis
           </h3>
           <p className="text-sm text-text-secondary leading-relaxed flex-1 overflow-y-auto max-h-[300px] custom-scrollbar">
             {data?.insight || "Analizando el panorama competitivo para detectar vacíos legales y cuellos de botella en la cadena de suministro..."}
           </p>
           <button className="w-full py-4 mt-8 bg-primary/10 border border-primary/30 hover:bg-primary text-white font-bold rounded-xl transition-all active:scale-95">
             {t.reportPdf}
           </button>
        </div>
      </div>
    </div>
  );
};

export default Analysis;
