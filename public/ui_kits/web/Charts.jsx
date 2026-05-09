// Charts.jsx — simple SVG charts

const BarChart = ({ data, activeIdx = -1, height = 160 }) => {
  const max = Math.max(...data.map(d => d.value));
  return (
    <div style={{display:'flex', alignItems:'end', gap: 10, height, paddingTop: 8}}>
      {data.map((d, i) => {
        const h = Math.max(8, (d.value / max) * (height - 28));
        return (
          <div key={i} style={{flex:1, display:'flex', flexDirection:'column', alignItems:'center', gap: 6}}>
            <div style={{
              width: '100%', maxWidth: 26, height: h,
              borderRadius: '6px 6px 0 0',
              background: i === activeIdx ? 'var(--color-primary)' : 'var(--color-primary-bg)',
              border: i === activeIdx ? 'none' : `1px solid var(--color-primary)`,
              transition: 'background 200ms',
            }}/>
            <span style={{fontSize: 11, color: 'var(--color-fg-muted)'}}>{d.label}</span>
          </div>
        );
      })}
    </div>
  );
};

// Income vs expenses grouped bars
const GroupedBars = ({ labels, income, expenses, height = 240 }) => {
  const W = 600, H = height, padL = 36, padR = 12, padT = 16, padB = 28;
  const max = Math.max(...income, ...expenses);
  const groupW = (W - padL - padR) / labels.length;
  const barW = (groupW - 8) / 2;

  return (
    <svg viewBox={`0 0 ${W} ${H}`} style={{width:'100%', height}}>
      {[0,1,2,3].map(i => {
        const y = padT + (i/3)*(H - padT - padB);
        return <line key={i} x1={padL} x2={W-padR} y1={y} y2={y} stroke="var(--color-border)" strokeWidth="1"/>;
      })}
      {labels.map((l, i) => {
        const xCenter = padL + i*groupW + groupW/2;
        const incH = (income[i]/max) * (H - padT - padB);
        const expH = (expenses[i]/max) * (H - padT - padB);
        return (
          <g key={i}>
            <rect x={xCenter - barW - 2} y={H - padB - incH} width={barW} height={incH}
                  fill="var(--success-500)" rx="3"/>
            <rect x={xCenter + 2} y={H - padB - expH} width={barW} height={expH}
                  fill="var(--danger-500)" rx="3"/>
            <text x={xCenter} y={H-8} fontSize="11" fill="var(--color-fg-muted)" textAnchor="middle">{l}</text>
          </g>
        );
      })}
    </svg>
  );
};

const Donut = ({ slices, size = 200, thickness = 18 }) => {
  const total = slices.reduce((s, x) => s + x.value, 0);
  const r = 50 - thickness / 2;
  const C = 2 * Math.PI * r;
  let offset = 0;
  return (
    <svg viewBox="0 0 100 100" width={size} height={size} style={{transform:'rotate(-90deg)'}}>
      {slices.map((s, i) => {
        const frac = total > 0 ? s.value / total : 0;
        const len = frac * C;
        const gap = 1.5;
        const dash = `${Math.max(0, len - gap)} ${C - Math.max(0, len - gap)}`;
        const dashoffset = -offset;
        offset += len;
        return (
          <circle key={i} cx="50" cy="50" r={r}
                  fill="none" stroke={s.color}
                  strokeWidth={thickness}
                  strokeDasharray={dash} strokeDashoffset={dashoffset}/>
        );
      })}
    </svg>
  );
};

window.BarChart = BarChart;
window.GroupedBars = GroupedBars;
window.Donut = Donut;
