// Sidebar.jsx — left navigation, mirrors structure in dashboard.php sidebar.

const NAV = [
  { id: 'dashboard',    label: 'Dashboard',    icon: 'home' },
  { id: 'budget',       label: 'Budget',       icon: 'pie' },
  { id: 'transactions', label: 'Transactions', icon: 'list' },
  { id: 'analytics',    label: 'Analytics',    icon: 'chart' },
  { id: 'accounts',     label: 'Accounts',     icon: 'wallet' },
];
const NAV_PLAN = [
  { id: 'goals',        label: 'Goals',        icon: 'target' },
  { id: 'recurring',    label: 'Recurring',    icon: 'repeat' },
  { id: 'loans',        label: 'Loans',        icon: 'book' },
];
const NAV_FOOT = [
  { id: 'settings',     label: 'Settings',     icon: 'cog' },
];

const Sidebar = ({ active, onNavigate }) => {
  const { LEDGERS } = window.PG_DATA;
  const [ledger, setLedger] = React.useState(LEDGERS.find(l => l.active));

  return (
    <aside className="sidebar">
      <div className="brand">
        <div className="mark">P</div>
        <span>PgBudget</span>
      </div>

      {/* Ledger picker */}
      <div style={{
        display:'flex', alignItems:'center', justifyContent:'space-between',
        padding:'var(--space-2) var(--space-3)', border:'1px solid var(--color-border)',
        borderRadius: 'var(--radius-md)', cursor:'pointer'
      }}>
        <div>
          <div style={{fontSize: 'var(--text-xs)', color:'var(--color-fg-muted)'}}>Ledger</div>
          <div style={{fontSize: 'var(--text-sm)', fontWeight: 600}}>{ledger.name}</div>
        </div>
        <Icon name="chevD" size={16}/>
      </div>

      <nav style={{display:'flex', flexDirection:'column', gap: 2, marginTop: 'var(--space-2)'}}>
        {NAV.map(n => (
          <div key={n.id} className={`nav-item ${active === n.id ? 'active' : ''}`}
               onClick={() => onNavigate(n.id)}>
            <Icon name={n.icon}/>
            <span>{n.label}</span>
          </div>
        ))}
      </nav>

      <div className="group-label">Plan</div>
      <nav style={{display:'flex', flexDirection:'column', gap: 2}}>
        {NAV_PLAN.map(n => (
          <div key={n.id} className={`nav-item ${active === n.id ? 'active' : ''}`}
               onClick={() => onNavigate(n.id)}>
            <Icon name={n.icon}/>
            <span>{n.label}</span>
          </div>
        ))}
      </nav>

      <div style={{flex: 1}}/>
      <nav style={{display:'flex', flexDirection:'column', gap: 2}}>
        {NAV_FOOT.map(n => (
          <div key={n.id} className={`nav-item ${active === n.id ? 'active' : ''}`}
               onClick={() => onNavigate(n.id)}>
            <Icon name={n.icon}/>
            <span>{n.label}</span>
          </div>
        ))}
      </nav>

      {/* User chip */}
      <div style={{
        display:'flex', alignItems:'center', gap: 'var(--space-3)',
        padding:'var(--space-2) var(--space-3)', borderTop:'1px solid var(--color-border)',
        marginTop: 'var(--space-2)', paddingTop: 'var(--space-3)',
      }}>
        <div style={{
          width: 32, height: 32, borderRadius: '50%',
          background: 'var(--color-primary-bg)',
          color: 'var(--color-primary-700)',
          display:'grid', placeItems:'center',
          fontWeight: 700, fontSize: 13
        }}>AR</div>
        <div style={{minWidth: 0, flex: 1}}>
          <div style={{fontSize: 'var(--text-sm)', fontWeight: 600}}>Alex Rivera</div>
          <div style={{fontSize: 'var(--text-xs)', color:'var(--color-fg-muted)'}}>alex@mail.com</div>
        </div>
      </div>
    </aside>
  );
};

window.Sidebar = Sidebar;
