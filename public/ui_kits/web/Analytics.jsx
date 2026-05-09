// Analytics.jsx — period selector + summary cards + income/expense chart.

const Analytics = () => {
  const { CASHFLOW_LABELS, CASHFLOW_INFLOW, CASHFLOW_OUTFLOW, BUDGET_GROUPS } = window.PG_DATA;
  const [range, setRange] = React.useState('year');

  const totalIn  = CASHFLOW_INFLOW.reduce((s,v) => s + v, 0);
  const totalOut = CASHFLOW_OUTFLOW.reduce((s,v) => s + v, 0);
  const net      = totalIn - totalOut;

  // donut by category group
  const groupSpend = BUDGET_GROUPS.map((g, i) => ({
    name: g.name,
    value: g.categories.reduce((s,c) => s + Math.abs(c.activity), 0),
    color: ['var(--color-primary)','#22c55e','#f59e0b','#ef4444','#8b5cf6','#06b6d4'][i % 6],
  })).filter(s => s.value > 0);

  return (
    <>
      <div style={{display:'flex', justifyContent:'space-between', alignItems:'center'}}>
        <div>
          <span className="eyebrow">Last 6 months</span>
          <h2 className="t-h2" style={{margin: '4px 0 0'}}>Cash flow overview</h2>
        </div>
        <div className="toggle">
          <button className={"seg" + (range === 'week'  ? ' on' : '')} onClick={() => setRange('week')}>Week</button>
          <button className={"seg" + (range === 'month' ? ' on' : '')} onClick={() => setRange('month')}>Month</button>
          <button className={"seg" + (range === 'year'  ? ' on' : '')} onClick={() => setRange('year')}>Year</button>
        </div>
      </div>

      {/* Summary cards */}
      <div style={{display:'grid', gridTemplateColumns:'repeat(3, 1fr)', gap:'var(--space-4)'}}>
        <div className="card">
          <div style={{display:'flex', justifyContent:'space-between', alignItems:'flex-start'}}>
            <span className="eyebrow">Total income</span>
            <span className="badge badge-success">+12.4%</span>
          </div>
          <div className="tnum" style={{fontSize:'var(--text-3xl)', fontWeight:700, marginTop:8}}>${window.fmt(totalIn)}</div>
          <div style={{fontSize:'var(--text-xs)', color:'var(--color-fg-muted)', marginTop:4}}>vs ${window.fmt(totalIn-2400)} prior period</div>
        </div>
        <div className="card">
          <div style={{display:'flex', justifyContent:'space-between', alignItems:'flex-start'}}>
            <span className="eyebrow">Total expenses</span>
            <span className="badge badge-danger">−4.1%</span>
          </div>
          <div className="tnum" style={{fontSize:'var(--text-3xl)', fontWeight:700, marginTop:8}}>${window.fmt(totalOut)}</div>
          <div style={{fontSize:'var(--text-xs)', color:'var(--color-fg-muted)', marginTop:4}}>vs ${window.fmt(totalOut+700)} prior period</div>
        </div>
        <div className="card" style={{background:'var(--color-primary-bg)', borderColor:'var(--color-primary)'}}>
          <div style={{display:'flex', justifyContent:'space-between', alignItems:'flex-start'}}>
            <span className="eyebrow" style={{color:'var(--color-primary-700)'}}>Net savings</span>
            <span className="badge" style={{background:'#fff', color:'var(--color-primary-700)'}}>This period</span>
          </div>
          <div className="tnum" style={{fontSize:'var(--text-3xl)', fontWeight:700, marginTop:8, color:'var(--color-primary-700)'}}>${window.fmt(net)}</div>
          <div style={{fontSize:'var(--text-xs)', color:'var(--color-primary-700)', marginTop:4, opacity: 0.85}}>~${window.fmt(Math.round(net/6))} per month</div>
        </div>
      </div>

      {/* Income vs expenses chart */}
      <div className="card">
        <div className="card-head">
          <h3 className="card-title">Income vs. expenses</h3>
          <div style={{display:'flex', gap: 'var(--space-4)'}}>
            <span style={{display:'flex', alignItems:'center', gap:6, fontSize:'var(--text-xs)'}}>
              <span style={{width:10, height:10, background:'var(--success-500)', borderRadius:2}}/> Income
            </span>
            <span style={{display:'flex', alignItems:'center', gap:6, fontSize:'var(--text-xs)'}}>
              <span style={{width:10, height:10, background:'var(--danger-500)', borderRadius:2}}/> Expenses
            </span>
          </div>
        </div>
        <GroupedBars
          labels={CASHFLOW_LABELS}
          income={CASHFLOW_INFLOW}
          expenses={CASHFLOW_OUTFLOW}
          height={260}/>
      </div>

      {/* Donut + breakdown */}
      <div className="card">
        <div className="card-head">
          <h3 className="card-title">Spending by group</h3>
          <span className="eyebrow">April</span>
        </div>
        <div style={{display:'flex', gap:'var(--space-8)', alignItems:'center'}}>
          <Donut slices={groupSpend} size={200}/>
          <div style={{flex: 1, display:'flex', flexDirection:'column', gap:'var(--space-3)'}}>
            {groupSpend.map(s => (
              <div key={s.name} style={{display:'flex', justifyContent:'space-between', alignItems:'center'}}>
                <div style={{display:'flex', alignItems:'center', gap:'var(--space-3)'}}>
                  <span style={{width:10, height:10, background: s.color, borderRadius:3}}/>
                  <span style={{fontSize:'var(--text-sm)', fontWeight:500}}>{s.name}</span>
                </div>
                <span className="tnum" style={{fontWeight:600}}>${window.fmt(s.value)}</span>
              </div>
            ))}
          </div>
        </div>
      </div>
    </>
  );
};

window.Analytics = Analytics;
