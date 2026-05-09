// Data.js — fake data shaped like real PgBudget rows.
// Uses YNAB-style zero-sum budgeting vocabulary from the codebase.

const LEDGERS = [
  { id: 'main',    name: 'Personal',    active: true },
  { id: 'family',  name: 'Family',      active: false },
  { id: 'studio',  name: 'Studio LLC',  active: false },
];

const ACCOUNTS = [
  // Asset accounts
  { id: 'a1', name: 'Chase Checking',     type: 'asset',     subtype: 'checking', balance:  4_283.42, icon: '🏦' },
  { id: 'a2', name: 'Ally Savings',       type: 'asset',     subtype: 'savings',  balance: 12_500.00, icon: '💰' },
  { id: 'a3', name: 'Cash Wallet',        type: 'asset',     subtype: 'cash',     balance:    142.00, icon: '💵' },
  // Liability accounts
  { id: 'l1', name: 'Chase Sapphire',     type: 'liability', subtype: 'credit',   balance:   -842.18, icon: '💳' },
  { id: 'l2', name: 'Auto Loan',          type: 'liability', subtype: 'loan',     balance:-12_400.00, icon: '🚗' },
];

// Budget category groups w/ categories (matches grouped-view shape in dashboard.php)
const BUDGET_GROUPS = [
  { id: 'monthly', name: 'Monthly Bills', categories: [
    { id: 'c-rent',    name: 'Rent',          icon: '🏠', budgeted: 1800, activity: -1800, available:    0 },
    { id: 'c-utility', name: 'Utilities',     icon: '💡', budgeted:  180, activity:  -142, available:   38 },
    { id: 'c-phone',   name: 'Phone',         icon: '📱', budgeted:   60, activity:   -60, available:    0 },
    { id: 'c-internet',name: 'Internet',      icon: '🌐', budgeted:   80, activity:   -80, available:    0 },
  ]},
  { id: 'living',  name: 'Everyday', categories: [
    { id: 'c-food',    name: 'Groceries',     icon: '🛒', budgeted:  500, activity:  -312, available:  188 },
    { id: 'c-trans',   name: 'Transport',     icon: '🚌', budgeted:  150, activity:   -88, available:   62 },
    { id: 'c-eat',     name: 'Eating Out',    icon: '🍕', budgeted:  200, activity:  -240, available:  -40 },
    { id: 'c-shop',    name: 'Shopping',      icon: '🛍️', budgeted:  150, activity:  -129, available:   21 },
  ]},
  { id: 'goals',   name: 'Goals & Savings', categories: [
    { id: 'c-ef',      name: 'Emergency Fund',icon: '🛟', budgeted:  300, activity:     0, available:  300, goal: { target: 10000, current: 6400 } },
    { id: 'c-trip',    name: 'Trip · Japan',  icon: '✈️', budgeted:  250, activity:     0, available:  250, goal: { target:  4000, current: 1750 } },
  ]},
  { id: 'cc',      name: 'Credit Card Payments', categories: [
    { id: 'c-cc',      name: 'Chase Sapphire',icon: '💳', budgeted:  500, activity:  -842, available: -342 },
  ]},
];

const TRANSACTIONS = [
  { id: 1,  date: 'Apr 24', payee: 'Whole Foods',          account: 'Chase Checking',   category: 'Groceries',   memo: '',                  inflow: 0,    outflow: 48.50 },
  { id: 2,  date: 'Apr 22', payee: 'Acme Inc · Payroll',   account: 'Chase Checking',   category: 'Inflow: Ready to Assign', memo: 'Apr 1–15', inflow: 3500, outflow: 0 },
  { id: 3,  date: 'Apr 21', payee: 'Lyft',                 account: 'Chase Sapphire',   category: 'Transport',   memo: 'Airport',           inflow: 0,    outflow: 42.40 },
  { id: 4,  date: 'Apr 20', payee: 'Apple.com',            account: 'Chase Sapphire',   category: 'Shopping',    memo: 'Magic Mouse',       inflow: 0,    outflow: 129.00 },
  { id: 5,  date: 'Apr 19', payee: 'Netflix',              account: 'Chase Sapphire',   category: 'Eating Out',  memo: '',                  inflow: 0,    outflow: 15.99 },
  { id: 6,  date: 'Apr 18', payee: 'PG&E',                 account: 'Chase Checking',   category: 'Utilities',   memo: 'April bill',        inflow: 0,    outflow: 84.20 },
  { id: 7,  date: 'Apr 17', payee: "Trader Joe's",         account: 'Chase Checking',   category: 'Groceries',   memo: '',                  inflow: 0,    outflow: 62.10 },
  { id: 8,  date: 'Apr 17', payee: 'Spotify',              account: 'Chase Sapphire',   category: 'Eating Out',  memo: '',                  inflow: 0,    outflow: 9.99 },
  { id: 9,  date: 'Apr 16', payee: 'Refund · Amazon',      account: 'Chase Checking',   category: 'Shopping',    memo: '',                  inflow: 24.30, outflow: 0 },
  { id: 10, date: 'Apr 15', payee: 'BART',                 account: 'Chase Checking',   category: 'Transport',   memo: '',                  inflow: 0,    outflow: 8.40 },
  { id: 11, date: 'Apr 14', payee: 'Chipotle',             account: 'Chase Sapphire',   category: 'Eating Out',  memo: '',                  inflow: 0,    outflow: 14.85 },
  { id: 12, date: 'Apr 13', payee: 'Comcast',              account: 'Chase Checking',   category: 'Internet',    memo: '',                  inflow: 0,    outflow: 79.99 },
  { id: 13, date: 'Apr 12', payee: 'Transfer to Savings',  account: 'Chase Checking',   category: '— Transfer —',memo: 'Monthly EF',        inflow: 0,    outflow: 300.00 },
  { id: 14, date: 'Apr 11', payee: 'Zara',                 account: 'Chase Sapphire',   category: 'Shopping',    memo: '',                  inflow: 0,    outflow: 68.50 },
  { id: 15, date: 'Apr 10', payee: 'Stripe payout',        account: 'Chase Checking',   category: 'Inflow: Ready to Assign', memo: 'Freelance', inflow: 1240, outflow: 0 },
];

const CASHFLOW_LABELS   = ['Nov','Dec','Jan','Feb','Mar','Apr'];
const CASHFLOW_INFLOW   = [3500, 3500, 3700, 3700, 4400, 4764];
const CASHFLOW_OUTFLOW  = [3120, 3340, 1980, 2340, 2790, 1860];

window.PG_DATA = { LEDGERS, ACCOUNTS, BUDGET_GROUPS, TRANSACTIONS, CASHFLOW_LABELS, CASHFLOW_INFLOW, CASHFLOW_OUTFLOW };
