// Dashboard.jsx — Available to Budget hero, setup checklist, accounts, recent activity.

const Dashboard = ({ onNavigate }) => {
  const { TRANSACTIONS, ACCOUNTS, CASHFLOW_LABELS, CASHFLOW_INFLOW, CASHFLOW_OUTFLOW, BUDGET_GROUPS } = window.PG_DATA;

  const recent = TRANSACTIONS.slice(0, 6);
  const totalAssets    = ACCOUNTS.filter(a => a.type === 'asset').reduce((s,a) => s + a.balance, 0);
  const totalLiabs     = ACCOUNTS.filter(a => a.type === 'liability').reduce((s,a) => s + a.balance, 0);

  // Aggregate "Available to Budget" — inflow minus assigned (budgeted)
  const inflowMonth  = CASHFLOW_INFLOW[CASHFLOW_INFLOW.length - 1];
  const budgeted     = BUDGET_GROUPS.flatMap(g => g.categories).reduce((s,c) => s + c.budgeted, 0);
  const ready        = inflowMonth - budgeted; // intentionally small, often near zero

  // bar chart data: 6 months of outflow
  const spendingData = CASHFLOW_LABELS.map((l, i) => ({ label: l, value: CASHFLOW_OUTFLOW[i] }));

  return (
    <>
      {/* Overspending banner — matches the warning in dashboard.php */}
      <div className="banner">
        <span className="emoji">⚠️</span>
        <div>
          <b>1 category is overspent.</b> Eating Out is $40.00 over budget for April.
          Cover the overspending by reassigning money from another category.
        </div>
      </div>

      {/* Hero: Available to Budget */}
      <div style={{display:'grid', gridTemplateColumns:'1.4fr 1fr', gap: 'var(--space-5)'}}>
        <div className="hero-card">
          <span className="eyebrow">Ready to Assign · April</span>
          <div className="number">${window.fmt(ready)}</div>
          <div className="meta">
            ${window.fmt(inflowMonth)} income this month · ${window.fmt(budgeted)} assigned
          </div>
          <div style={{display:'flex', gap: 'var(--space-2)', marginTop: 'var(--space-5)', flexWrap:'wrap'}}>
            <button className="btn" style={{background:'#fff', color:'var(--color-primary-700)'}}>
              <span style={{fontSize:14}}>💵</span> Assign money
            </button>
            <button className="btn" style={{background:'rgba(255,255,255,0.18)', color:'#fff'}}>
              <span style={{fontSize:14}}>⚡</span> Quick add
            </button>
            <button className="btn" style={{background:'rgba(255,255,255,0.18)', color:'#fff'}}>
              <span style={{fontSize:14}}>⇄</span> Transfer
            </button>
          </div>
        </div>

        {/* Net worth tile */}
        <div className="card" style={{display:'flex', flexDirection:'column', justifyContent:'space-between'}}>
          <div>
            <span className="eyebrow">Net worth</span>
            <div className="tnum" style={{fontSize:'var(--text-3xl)', fontWeight:700, letterSpacing:'-0.02em', marginTop:8}}>
              ${window.fmt(totalAssets + totalLiabs)}
            </div>
            <div style={{display:'flex', gap:'var(--space-4)', marginTop: 'var(--space-3)'}}>
              <div>
                <div style={{fontSize:'var(--text-xs)', color:'var(--color-fg-muted)'}}>Assets</div>
                <div className="money pos tnum" style={{fontSize:'var(--text-base)'}}>+${window.fmt(totalAssets)}</div>
              </div>
              <div>
                <div style={{fontSize:'var(--text-xs)', color:'var(--color-fg-muted)'}}>Liabilities</div>
                <div className="money neg tnum" style={{fontSize:'var(--text-base)'}}>−${window.fmt(Math.abs(totalLiabs))}</div>
              </div>
            </div>
          </div>
          <div style={{marginTop:'var(--space-4)'}}>
            <Sparkbar data={CASHFLOW_INFLOW.map((v,i) => v - CASHFLOW_OUTFLOW[i])} height={36}/>
            <div style={{fontSize:'var(--text-xs)', color:'var(--color-fg-muted)', marginTop:6}}>Net cash flow · last 6 mo</div>
          </div>
        </div>
      </div>

      {/* Setup checklist + Spending chart */}
      <div style={{display:'grid', gridTemplateColumns:'1fr 1.3fr', gap: 'var(--space-5)'}}>
        <div className="card">
          <div className="card-head">
            <div>
              <span className="eyebrow">Get started</span>
              <h3 className="card-title" style={{marginTop: 4}}>Setup checklist</h3>
            </div>
            <span className="badge badge-info">3 of 5</span>
          </div>
          <div className="checklist">
            <div className="check done">
              <span className="dot"><Icon name="check" size={12}/></span>
              <span className="label">Create your first ledger</span>
            </div>
            <div className="check done">
              <span className="dot"><Icon name="check" size={12}/></span>
              <span className="label">Add an account</span>
            </div>
            <div className="check done">
              <span className="dot"><Icon name="check" size={12}/></span>
              <span className="label">Set up budget categories</span>
            </div>
            <div className="check">
              <span className="dot"></span>
              <span className="label">Record your first transaction</span>
            </div>
            <div className="check">
              <span className="dot"></span>
              <span className="label">Set a savings goal</span>
            </div>
          </div>
        </div>

        <div className="card">
          <div className="card-head">
            <div>
              <span className="eyebrow">Monthly outflow</span>
              <h3 className="card-title" style={{marginTop: 4}}>${window.fmt(CASHFLOW_OUTFLOW[CASHFLOW_OUTFLOW.length-1])} in April</h3>
            </div>
            <div className="toggle">
              <button className="seg">3M</button>
              <button className="seg on">6M</button>
              <button className="seg">1Y</button>
            </div>
          </div>
          <BarChart data={spendingData} activeIdx={spendingData.length-1} height={180}/>
        </div>
      </div>

      {/* Accounts + Recent activity */}
      <div style={{display:'grid', gridTemplateColumns:'1fr 1.3fr', gap: 'var(--space-5)'}}>
        <div className="card" style={{padding: 0}}>
          <div className="card-head" style={{padding:'var(--space-5) var(--space-5) var(--space-3)', margin:0}}>
            <h3 className="card-title">Accounts</h3>
            <button className="btn btn-ghost btn-sm">Manage</button>
          </div>
          <div style={{padding: '0 var(--space-5) var(--space-5)'}}>
            <div style={{fontSize:'var(--text-xs)', color:'var(--color-fg-muted)', textTransform:'uppercase', letterSpacing:'0.06em', margin:'var(--space-2) 0'}}>Assets</div>
            <div style={{display:'flex', flexDirection:'column', gap: 'var(--space-2)'}}>
              {ACCOUNTS.filter(a => a.type === 'asset').map(a => (
                <div key={a.id} className="account-card" onClick={() => onNavigate('accounts')}>
                  <div className="cat-icon">{a.icon}</div>
                  <div className="meta">
                    <div className="name">{a.name}</div>
                    <div className="type">{a.subtype}</div>
                  </div>
                  <Money value={a.balance}/>
                </div>
              ))}
            </div>
            <div style={{fontSize:'var(--text-xs)', color:'var(--color-fg-muted)', textTransform:'uppercase', letterSpacing:'0.06em', margin:'var(--space-4) 0 var(--space-2)'}}>Liabilities</div>
            <div style={{display:'flex', flexDirection:'column', gap: 'var(--space-2)'}}>
              {ACCOUNTS.filter(a => a.type === 'liability').map(a => (
                <div key={a.id} className="account-card">
                  <div className="cat-icon">{a.icon}</div>
                  <div className="meta">
                    <div className="name">{a.name}</div>
                    <div className="type">{a.subtype}</div>
                  </div>
                  <Money value={a.balance}/>
                </div>
              ))}
            </div>
          </div>
        </div>

        <div className="card" style={{padding: 0}}>
          <div className="card-head" style={{padding:'var(--space-5) var(--space-5) var(--space-3)', margin:0}}>
            <h3 className="card-title">Recent activity</h3>
            <button className="btn btn-ghost btn-sm" onClick={() => onNavigate('transactions')}>View all</button>
          </div>
          <div>
            {recent.map((t, i) => {
              const value = t.inflow > 0 ? t.inflow : -t.outflow;
              return (
                <div key={t.id} style={{
                  display:'grid', gridTemplateColumns: '40px 1fr auto',
                  gap: 'var(--space-3)', alignItems:'center',
                  padding: 'var(--space-3) var(--space-5)',
                  borderTop: '1px solid var(--color-border)',
                }}>
                  <div className="cat-icon" style={{width: 32, height: 32, fontSize: 14}}>
                    {t.payee[0].toUpperCase()}
                  </div>
                  <div style={{minWidth: 0}}>
                    <div style={{fontSize:'var(--text-sm)', fontWeight: 600, whiteSpace:'nowrap', overflow:'hidden', textOverflow:'ellipsis'}}>{t.payee}</div>
                    <div style={{fontSize:'var(--text-xs)', color:'var(--color-fg-muted)', marginTop:2}}>{t.category} · {t.date}</div>
                  </div>
                  <Money value={value} signed/>
                </div>
              );
            })}
          </div>
        </div>
      </div>
    </>
  );
};

window.Dashboard = Dashboard;
