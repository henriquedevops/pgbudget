// Primitives.jsx — small reusable bits

const fmt = (n) => {
  const a = Math.abs(n);
  return a.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
};

const Money = ({ value, signed = false, className = '', size }) => {
  const cls = value > 0 ? 'pos' : value < 0 ? 'neg' : 'zero';
  const sign = value > 0 && signed ? '+' : value < 0 ? '−' : '';
  return (
    <span className={`money ${cls} ${className}`} style={size ? {fontSize: size} : null}>
      {sign}${fmt(value)}
    </span>
  );
};

const Bar = ({ value, max, over }) => {
  const pct = Math.max(0, Math.min(100, (value / max) * 100));
  return (
    <div className={`bar ${over ? 'over' : ''}`}>
      <i style={{ width: `${pct}%` }}/>
    </div>
  );
};

const Sparkbar = ({ data, height = 28 }) => {
  const max = Math.max(...data);
  return (
    <div style={{display:'flex', gap: 2, alignItems:'flex-end', height}}>
      {data.map((v, i) => (
        <div key={i} style={{
          flex: 1, height: `${(v/max)*100}%`,
          background: 'var(--color-primary)', opacity: 0.25 + 0.75*(v/max),
          borderRadius: 2,
        }}/>
      ))}
    </div>
  );
};

// Tiny SVG icon set — stroke icons for sidebar / actions.
const Icon = ({ name, size = 18 }) => {
  const paths = {
    home:    'M3 11 12 4l9 7v9a1 1 0 0 1-1 1h-5v-6h-6v6H4a1 1 0 0 1-1-1z',
    list:    'M4 6h16M4 12h16M4 18h10',
    pie:     'M21 12a9 9 0 1 1-9-9v9z M21 12h-9V3',
    chart:   'M4 19V5 M4 19h16 M8 16V10 M12 16V7 M16 16V13',
    wallet:  'M3 7h15a3 3 0 0 1 3 3v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7zm0 0V6a2 2 0 0 1 2-2h12 M17 13h.01',
    cog:     'M12 8.5a3.5 3.5 0 1 0 0 7 3.5 3.5 0 0 0 0-7z M19.4 12c0-.4 0-.8-.1-1.2l2-1.5-2-3.5-2.4.9c-.6-.4-1.3-.8-2-1l-.4-2.6h-4l-.4 2.6c-.7.2-1.4.5-2 1l-2.4-.9-2 3.5 2 1.5c-.1.4-.1.8-.1 1.2s0 .8.1 1.2l-2 1.5 2 3.5 2.4-.9c.6.5 1.3.8 2 1l.4 2.6h4l.4-2.6c.7-.2 1.4-.5 2-1l2.4.9 2-3.5-2-1.5c.1-.4.1-.8.1-1.2z',
    plus:    'M12 5v14M5 12h14',
    search:  'M11 4a7 7 0 1 1 0 14 7 7 0 0 1 0-14z M21 21l-4.3-4.3',
    bell:    'M6 8a6 6 0 1 1 12 0c0 7 3 7 3 9H3c0-2 3-2 3-9z M10 21a2 2 0 0 0 4 0',
    chevR:   'M9 6l6 6-6 6',
    chevD:   'M6 9l6 6 6-6',
    check:   'M5 12l5 5L20 7',
    target:  'M12 12 m-9 0 a 9 9 0 1 0 18 0 a 9 9 0 1 0 -18 0  M12 12 m-5 0 a 5 5 0 1 0 10 0 a 5 5 0 1 0 -10 0  M12 12 m-1.5 0 a 1.5 1.5 0 1 0 3 0 a 1.5 1.5 0 1 0 -3 0',
    repeat:  'M17 1l4 4-4 4 M3 11V9a4 4 0 0 1 4-4h14 M7 23l-4-4 4-4 M21 13v2a4 4 0 0 1-4 4H3',
    book:    'M4 4h12a4 4 0 0 1 4 4v12 M4 4v16h12a4 4 0 0 0 4-4 M4 4a2 2 0 0 0 0 4h12',
    alert:   'M12 3 22 20H2z M12 10v5 M12 18v.01',
  };
  const d = paths[name];
  return (
    <svg viewBox="0 0 24 24" width={size} height={size} fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
      <path d={d}/>
    </svg>
  );
};

window.fmt = fmt;
window.Money = Money;
window.Bar = Bar;
window.Sparkbar = Sparkbar;
window.Icon = Icon;
