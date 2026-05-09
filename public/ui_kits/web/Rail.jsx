// Rail.jsx — right-side rail with quick stats, upcoming, and tip.

const Rail = () => {
  const { TRANSACTIONS, CASHFLOW_INFLOW, CASHFLOW_OUTFLOW } = window.PG_DATA;
  const recent = TRANSACTIONS.slice(0, 4);
  const inMonth  = CASHFLOW_INFLOW[CASHFLOW_INFLOW.length - 1];
  const outMonth = CASHFLOW_OUTFLOW[CASHFLOW_OUTFLOW.length - 1];

  return (
    <>
      <div>
        <div style={{display:'flex', justifyContent:'space-between', alignItems:'center', marginBottom: 'var(--space-3)'}}>
          <h3 className="card-title" style={{fontSize:'var(--text-base)'}}>April at a glance</h3>
          <button className="btn btn-ghost btn-icon" style={{width:28, height:28}}>
            <Icon name="bell" size={14}/>
          </button>
        </div>
        <div style={{display:'flex', flexDirection:'column', gap: 'var(--space-2)'}}>
          <Stat label="Income" value={inMonth} delta="+12.4%" deltaPos/>
          <Stat label="Expenses" value={outMonth} delta="−4.1%" deltaPos={false}/>
          <Stat label="Net" value={inMonth - outMonth} primary/>
        </div>
      </div>

      <div>
        <div style={{display:'flex', justifyContent:'space-between', alignItems:'center', marginBottom: 'var(--space-3)'}}>
          <h3 className="card-title" style={{fontSize:'var(--text-base)'}}>Recent activity</h3>
          <button className="btn btn-ghost btn-sm">View</button>
        </div>
        <div style={{display:'flex', flexDirection:'column', gap: 'var(--space-3)'}}>
          {recent.map(t => {
            const v = t.inflow > 0 ? t.inflow : -t.outflow;
            return (
              <div key={t.id} style={{display:'flex', alignItems:'center', gap: 'var(--space-2)'}}>
                <div className="cat-icon" style={{width: 30, height: 30, fontSize: 13}}>{t.payee[0].toUpperCase()}</div>
                <div style={{flex: 1, minWidth: 0}}>
                  <div style={{fontSize:'var(--text-sm)', fontWeight:600, whiteSpace:'nowrap', overflow:'hidden', textOverflow:'ellipsis'}}>{t.payee}</div>
                  <div style={{fontSize:'var(--text-xs)', color:'var(--color-fg-muted)'}}>{t.date}</div>
                </div>
                <Money value={v} signed/>
              </div>
            );
          })}
        </div>
      </div>

      <div className="banner info" style={{flexDirection:'column', gap: 'var(--space-2)'}}>
        <div style={{display:'flex', alignItems:'center', gap: 'var(--space-2)', fontWeight:600}}>
          <span className="emoji">💡</span> Tip
        </div>
        <div style={{fontSize:'var(--text-xs)', lineHeight: 1.5}}>
          You\u2019ve assigned 96% of April\u2019s income. Move $208 to <b>Eating Out</b> to cover overspending and reach zero.
        </div>
        <button className="btn btn-primary btn-sm" style={{alignSelf:'flex-start'}}>Auto-balance</button>
      </div>
    </>
  );
};

const Stat = ({ label, value, delta, deltaPos, primary }) => (
  <div style={{
    padding: 'var(--space-3) var(--space-4)',
    border: '1px solid var(--color-border)',
    borderRadius: 'var(--radius-md)',
    background: primary ? 'var(--color-primary-bg)' : 'transparent',
    borderColor: primary ? 'var(--color-primary)' : 'var(--color-border)',
  }}>
    <div style={{display:'flex', justifyContent:'space-between'}}>
      <span style={{fontSize:'var(--text-xs)', color: primary ? 'var(--color-primary-700)' : 'var(--color-fg-muted)'}}>{label}</span>
      {delta && <span className={"money " + (deltaPos ? 'pos' : 'neg')} style={{fontSize:'var(--text-xs)'}}>{delta}</span>}
    </div>
    <div className="tnum" style={{
      fontSize:'var(--text-xl)', fontWeight:700, marginTop: 4,
      color: primary ? 'var(--color-primary-700)' : 'var(--color-fg)',
    }}>${window.fmt(value)}</div>
  </div>
);

window.Rail = Rail;
