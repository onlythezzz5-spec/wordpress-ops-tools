
import React, { useState, useEffect } from 'react';
import { MetricCardProps, Language } from '../types';
import { analyzeMarketTrends } from '../services/geminiService';
import { translations } from '../translations';

interface DashboardProps {
  lang: Language;
}

const Sparkline: React.FC<{ data: number[]; trend: 'up' | 'down' }> = ({ data, trend }) => {
  const max = Math.max(...data);
  const min = Math.min(...data);
  const range = max - min || 1;
  const width = 100;
  const height = 30;
  
  const points = data.map((val, i) => {
    const x = (i / (data.length - 1)) * width;
    const y = height - ((val - min) / range) * height;
    return `${x},${y}`;
  }).join(' ');

  const color = trend === 'up' ? '#10b981' : '#ef4444';

  return (
    <svg width="100%" height={height} viewBox={`0 0 ${width} ${height}`} preserveAspectRatio="none" className="mt-2">
      <polyline
        fill="none"
        stroke={color}
        strokeWidth="2"
        strokeLinecap="round"
        strokeLinejoin="round"
        points={points}
      />
    </svg>
  );
};

const MetricCard: React.FC<MetricCardProps> = ({ label, value, change, trend, icon, trendData }) => (
  <div className="p-5 rounded-xl border border-border bg-surface hover:border-primary/50 transition-all group">
    <div className="flex justify-between items-start mb-3">
      <p className="text-text-secondary text-xs font-medium uppercase tracking-wider">{label}</p>
      <span className="material-symbols-outlined text-text-secondary group-hover:text-white text-[20px] transition-colors">{icon}</span>
    </div>
    <div className="flex items-baseline gap-2">
      <h3 className="text-white text-2xl font-bold font-display">{value}</h3>
      <span className={`text-[11px] font-bold px-1.5 py-0.5 rounded flex items-center ${
        trend === 'up' ? 'text-emerald-500 bg-emerald-500/10' : 'text-red-500 bg-red-500/10'
      }`}>
        {change}
      </span>
    </div>
    {trendData && <Sparkline data={trendData} trend={trend} />}
  </div>
);

const MarketSummary: React.FC<{ lang: Language }> = ({ lang }) => {
  const t = translations[lang];
  const [summary, setSummary] = useState<string>('');
  const [loading, setLoading] = useState<boolean>(true);

  useEffect(() => {
    const fetchSummary = async () => {
      setLoading(true);
      try {
        const query = lang === 'es' 
          ? "tendencias generales de e-commerce y productos ganadores 2026"
          : `General e-commerce trends and winning products for 2026 in ${lang}`;
        const result = await analyzeMarketTrends(query);
        setSummary(result || "No data");
      } catch (error) {
        setSummary("Error");
      } finally {
        setLoading(false);
      }
    };
    fetchSummary();
  }, [lang]);

  return (
    <div className="relative overflow-hidden rounded-2xl border border-primary/20 bg-gradient-to-r from-primary/5 to-transparent p-6 mb-8">
      <div className="flex items-start gap-4 relative z-10">
        <div className="size-10 rounded-lg bg-primary/20 flex items-center justify-center text-primary shrink-0">
          <span className="material-symbols-outlined">psychology</span>
        </div>
        <div className="space-y-2 flex-1">
          <div className="flex items-center gap-2">
            <h3 className="text-white font-bold font-display uppercase tracking-wider text-sm">IA Market Insight 2026</h3>
            <span className="px-2 py-0.5 rounded bg-primary text-[10px] text-white font-bold animate-pulse">LIVE</span>
          </div>
          {loading ? (
            <div className="h-4 bg-surface-light rounded w-3/4 animate-pulse"></div>
          ) : (
            <p className="text-text-secondary text-sm leading-relaxed italic max-w-5xl">
              "{summary.length > 300 ? summary.slice(0, 300) + '...' : summary}"
            </p>
          )}
        </div>
      </div>
    </div>
  );
};

const Dashboard: React.FC<DashboardProps> = ({ lang }) => {
  const t = translations[lang];
  const mockTrends = {
    n: [40, 45, 42, 50, 55, 52, 60],
    o: [7.2, 7.5, 7.8, 8.0, 8.2, 8.4],
    e: [20, 40, 35, 60, 80, 75, 95],
    v: [3.1, 3.4, 3.2, 3.8, 4.0, 4.2]
  };

  return (
    <div className="p-8 space-y-8 max-w-[1600px] mx-auto animate-in fade-in duration-500">
      <MarketSummary lang={lang} />

      <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
        <MetricCard label={t.metrics.totalNiches} value="1,240" change="+12%" trend="up" icon="dataset" trendData={mockTrends.n} />
        <MetricCard label={t.metrics.avgOpportunity} value="8.4/10" change="+5%" trend="up" icon="score" trendData={mockTrends.o} />
        <MetricCard label={t.metrics.trendingCategory} value="Eco Toys" change="MAX" trend="up" icon="local_mall" trendData={mockTrends.e} />
        <MetricCard label={t.metrics.marketVolume} value="$4.2M" change="+22%" trend="up" icon="payments" trendData={mockTrends.v} />
      </div>

      <div className="rounded-2xl border border-border bg-surface overflow-hidden">
        <div className="px-6 py-4 border-b border-border flex flex-col md:flex-row md:items-center justify-between gap-4">
          <div>
            <h3 className="text-white text-lg font-bold font-display">{t.metrics.saturationTitle}</h3>
            <p className="text-text-secondary text-sm">{t.metrics.saturationDesc}</p>
          </div>
        </div>
        <div className="p-6">
           <div className="grid grid-cols-12 gap-2 h-[400px]">
             <div className="col-span-8 bg-emerald-500/10 border-2 border-emerald-500/30 rounded-xl p-6 flex flex-col justify-end">
                <span className="text-2xl font-bold text-white font-display">Pet Supplies</span>
                <p className="text-text-secondary text-xs">High Growth Potential 2026</p>
             </div>
             <div className="col-span-4 bg-red-500/10 border-2 border-red-500/30 rounded-xl p-6 flex flex-col justify-end">
                <span className="text-lg font-bold text-white font-display">Gadgets</span>
                <p className="text-text-secondary text-xs">Saturated Market</p>
             </div>
           </div>
        </div>
      </div>
    </div>
  );
};

export default Dashboard;
