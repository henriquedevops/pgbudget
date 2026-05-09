// Budget.jsx — main view: grouped Budget Categories table (YNAB-style).

const Budget = () => {
  const { BUDGET_GROUPS } = window.PG_DATA;
  const [view, setView] = React.useState('grouped'); // grouped | flat

  const all = BUDGET_GROUPS.flatMap(g => g.categories);
  const totalBudgeted = all.reduce((s,c) => s + c.budgeted, 0);
  const totalActivity = all.reduce((s,c) => s + c.activity, 0);
  const totalAvail    = all.reduce((s,c) => s + c.available, 0);

  return (
    <>
      {/* Summary strip — Total Budgeted / Total Spent / Total Available */}
      <div style={{display:'grid', gridTemplateColumns:'repeat(3, 1fr)', gap: 'var(--space-4)'}}>
        <div className="card">
          <span className="eyebrow">Total budgeted</span>
          <div className="tnum" style={{fontSize:'var(--text-2xl)', fontWeight:700, marginTop:6}}>${window.fmt(totalBudgeted)}</div>
        </div>
        <div className="card">
          <span className="eyebrow">Total spent</span>
          <div className="tnum money neg" style={{fontSize:'var(--text-2xl)', fontWeight:700, marginTop:6}}>
            ${window.fmt(Math.abs(totalActivity))}
          </div>
        </div>
        <div className="card" style={{borderColor:'var(--color-primary)', background:'var(--color-primary-bg)'}}>
          <span className="eyebrow" style={{color:'var(--color-primary-700)'}}>Total available</span>
          <div className="tnum" style={{fontSize:'var(--text-2xl)', fontWeight:700, marginTop:6, color:'var(--color-primary-700)'}}>
            ${window.fmt(totalAvail)}
          </div>
        </div>
      </div>

      {/* Toolbar */}
      <div style={{display:'flex', justifyContent:'space-between', alignItems:'center'}}>
        <div className="toggle">
          <button className={"seg" + (view==='grouped' ? ' on' : '')} onClick={() => setView('grouped')}>Grouped</button>
          <button className={"seg" + (view==='flat' ? ' on' : '')} onClick={() => setView('flat')}>Flat</button>
        </div>
        <div style={{display:'flex', gap:'var(--space-2)'}}>
          <button className="btn btn-secondary"><Icon name="repeat" size={14}/> Copy from last month</button>
          <button className="btn btn-primary"><Icon name="plus" size={14}/> New category</button>
        </div>
      </div>

      {/* The Budget Categories table */}
      <table className="tbl">
        <thead>
          <tr>
            <th>Category</th>
            <th className="num" style={{width:120}}>Budgeted</th>
            <th className="num" style={{width:120}}>Activity</th>
            <th className="num" style={{width:120}}>Available</th>
            <th style={{width:160}}>Progress</th>
            <th style={{width:40}}></th>
          </tr>
        </thead>
        <tbody>
          {view === 'grouped'
            ? BUDGET_GROUPS.flatMap(g => [
                <tr key={g.id} className="group-head"><td colSpan={6}>{g.name}</td></tr>,
                ...g.categories.map(c => <CategoryRow key={c.id} c={c}/>),
              ])
            : all.map(c => <CategoryRow key={c.id} c={c}/>)
          }
        </tbody>
      </table>

      {/* Goals row (matches goal cards in the app) */}
      <div className="card" style={{padding: 0}}>
        <div className="card-head" style={{padding:'var(--space-5) var(--space-5) var(--space-3)', margin: 0}}>
          <h3 className="card-title">Goals</h3>
          <button className="btn btn-ghost btn-sm">Add goal</button>
        </div>
        <div style={{display:'grid', gridTemplateColumns:'1fr 1fr', gap:0}}>
          {all.filter(c => c.goal).map((c, i) => {
            const pct = Math.round((c.goal.current / c.goal.target) * 100);
            return (
              <div key={c.id} style={{
                padding: 'var(--space-5)',
                borderTop: '1px solid var(--color-border)',
                borderRight: i % 2 === 0 ? '1px solid var(--color-border)' : 'none',
              }}>
                <div style={{display:'flex', alignItems:'center', gap: 'var(--space-3)'}}>
                  <div className="cat-icon">{c.icon}</div>
                  <div style={{flex: 1}}>
                    <div style={{fontWeight:600}}>{c.name}</div>
                    <div style={{fontSize: 'var(--text-xs)', color:'var(--color-fg-muted)'}}>
                      ${window.fmt(c.goal.current)} of ${window.fmt(c.goal.target)}
                    </div>
                  </div>
                  <span className="badge badge-info">{pct}%</span>
                </div>
                <div className="bar" style={{marginTop: 'var(--space-3)', height: 8}}>
                  <i style={{width: `${pct}%`}}/>
                </div>
              </div>
            );
          })}
        </div>
      </div>
    </>
  );
};

const CategoryRow = ({ c }) => {
  const pct = c.budgeted > 0 ? (Math.abs(c.activity) / c.budgeted) * 100 : 0;
  const over = c.available < 0;
  return (
    <tr>
      <td>
        <div style={{display:'flex', alignItems:'center', gap: 'var(--space-3)'}}>
          <div className="cat-icon" style={{width: 28, height: 28, fontSize: 14}}>{c.icon}</div>
          <span style={{fontWeight: 500}}>{c.name}</span>
          {over && <span className="badge badge-danger">Overspent</span>}
        </div>
      </td>
      <td className="num">${window.fmt(c.budgeted)}</td>
      <td className="num">{c.activity === 0 ? <span className="money zero">$0.00</span> : <Money value={c.activity}/>}</td>
      <td className="num">
        <span className={over ? 'money neg' : c.available > 0 ? 'money pos' : 'money zero'}>
          ${window.fmt(c.available)}
        </span>
      </td>
      <td>
        <Bar value={Math.abs(c.activity)} max={c.budgeted} over={over}/>
      </td>
      <td>
        <button className="btn btn-ghost btn-icon" style={{width: 28, height: 28}}>
          <Icon name="chevR" size={14}/>
        </button>
      </td>
    </tr>
  );
};

window.Budget = Budget;
