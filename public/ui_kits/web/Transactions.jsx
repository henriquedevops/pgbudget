// Transactions.jsx — full ledger view with search, tabs, account/category filters.

const Transactions = () => {
  const { TRANSACTIONS, ACCOUNTS } = window.PG_DATA;
  const [tab, setTab] = React.useState('all');
  const [q, setQ] = React.useState('');
  const [acct, setAcct] = React.useState('all');

  const filtered = TRANSACTIONS.filter(t => {
    if (tab === 'income' && t.inflow === 0) return false;
    if (tab === 'expenses' && t.outflow === 0) return false;
    if (acct !== 'all' && t.account !== acct) return false;
    if (q && !`${t.payee} ${t.category} ${t.memo}`.toLowerCase().includes(q.toLowerCase())) return false;
    return true;
  });

  return (
    <>
      {/* Filter row */}
      <div style={{display:'flex', gap:'var(--space-3)', alignItems:'center', flexWrap:'wrap'}}>
        <div className="search" style={{flex:'0 0 320px'}}>
          <Icon name="search" size={16}/>
          <input placeholder="Search payee, memo, category" value={q} onChange={e => setQ(e.target.value)}/>
        </div>
        <div className="toggle">
          <button className={"seg" + (tab === 'all' ? ' on' : '')} onClick={() => setTab('all')}>All</button>
          <button className={"seg" + (tab === 'income' ? ' on' : '')} onClick={() => setTab('income')}>Income</button>
          <button className={"seg" + (tab === 'expenses' ? ' on' : '')} onClick={() => setTab('expenses')}>Expenses</button>
        </div>
        <select className="input" style={{width:'auto'}} value={acct} onChange={e => setAcct(e.target.value)}>
          <option value="all">All accounts</option>
          {ACCOUNTS.map(a => <option key={a.id} value={a.name}>{a.name}</option>)}
        </select>
        <div style={{flex: 1}}/>
        <button className="btn btn-secondary"><Icon name="repeat" size={14}/> Apr 2026</button>
        <button className="btn btn-primary"><Icon name="plus" size={14}/> Add transaction</button>
      </div>

      {/* Table */}
      <table className="tbl">
        <thead>
          <tr>
            <th style={{width: 100}}>Date</th>
            <th>Payee</th>
            <th>Category</th>
            <th>Account</th>
            <th>Memo</th>
            <th className="num" style={{width:120}}>Outflow</th>
            <th className="num" style={{width:120}}>Inflow</th>
          </tr>
        </thead>
        <tbody>
          {filtered.map(t => (
            <tr key={t.id}>
              <td style={{color:'var(--color-fg-muted)', fontFeatureSettings:'var(--font-feature-tnum)'}}>{t.date}</td>
              <td>
                <div style={{display:'flex', alignItems:'center', gap: 'var(--space-3)'}}>
                  <div className="cat-icon" style={{width:28, height:28, fontSize:13}}>
                    {t.payee[0].toUpperCase()}
                  </div>
                  <span style={{fontWeight: 500}}>{t.payee}</span>
                </div>
              </td>
              <td>
                {t.category.startsWith('Inflow')
                  ? <span className="badge badge-success">{t.category}</span>
                  : t.category.startsWith('—')
                    ? <span className="badge badge-neutral">{t.category}</span>
                    : <span className="badge badge-neutral">{t.category}</span>
                }
              </td>
              <td style={{color:'var(--color-fg-muted)'}}>{t.account}</td>
              <td style={{color:'var(--color-fg-muted)'}}>{t.memo || '—'}</td>
              <td className="num">{t.outflow > 0 ? <span className="money neg">${window.fmt(t.outflow)}</span> : <span style={{color:'var(--color-fg-subtle)'}}>—</span>}</td>
              <td className="num">{t.inflow > 0 ? <span className="money pos">${window.fmt(t.inflow)}</span> : <span style={{color:'var(--color-fg-subtle)'}}>—</span>}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </>
  );
};

window.Transactions = Transactions;
