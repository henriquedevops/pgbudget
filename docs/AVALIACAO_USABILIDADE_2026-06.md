# Avaliação de Usabilidade — PGBudget

> **Data:** 2026-06-11
> **Método:** Avaliação heurística (Nielsen) por inspeção de código — páginas públicas (`public/`), includes compartilhados (`includes/`), CSS/JS (`css/`, `js/`)
> **Complementa:** `USABILITY_IMPROVEMENT_PLAN.md`, `USABILITY_IMPROVEMENTS_STATUS.md`, `UX_MODERNIZATION_PLAN.md`
> **Escopo:** usabilidade da interface web; não cobre Telegram bot, e-mails ou API
> **Atualização 2026-06-12:** U1, U2 (moeda), U4, U8 e U9 resolvidos (commits `ae9825b` e `f8f1cbb`) — status por item nas seções 3 e 4
> **Atualização 2026-06-13:** U5, U13, U14, U11 e U12 resolvidos; U7 e U15 parciais — status por item nas seções 3 e 4

---

## 1. Resumo Executivo

O PGBudget evoluiu muito em usabilidade: onboarding em 5 passos, modal de adição rápida, undo/redo global, atalhos de teclado estilo Gmail, dark mode automático, gestos mobile e o recente sweep do design kit (Phases 1–6). A base é sólida.

Os problemas restantes se concentram em **arquitetura de informação** (relatórios órfãos, navegação duplicada), **localização** (idioma e moeda fixos em inglês/dólar) e **consistência** (diálogos nativos `alert()`/`confirm()` convivendo com modais customizados, terminologia mista "Ledger/Budget" e "Bills/Obligations").

| Severidade | Quantidade |
|---|---|
| 🔴 Crítico | 4 |
| 🟠 Alto | 5 |
| 🟡 Médio | 6 |
| Pontos fortes | 10 |

---

## 2. Pontos Fortes

1. **Onboarding guiado** — wizard de 5 passos (`public/onboarding/`) com estado persistido no banco e redirecionamento automático para usuários novos; complementado pelo checklist "Get started" no dashboard (`budget/dashboard.php:230-262`) que acompanha contas → categorias → primeira transação.
2. **Undo/Redo global** na navbar (`includes/header.php:72-82`) — raro em apps financeiros e excelente para "controle e liberdade do usuário".
3. **Atalhos de teclado completos** (`js/keyboard-shortcuts.js`) — sequências `g+b`/`g+t` estilo Gmail, `j/k` para navegar listas, ajuda com `?`, e guarda correta para não disparar dentro de inputs (`isTypingInInput`).
4. **Busca acessível por `/` e `Ctrl+K`** com dica no placeholder.
5. **Quick Add modal** disponível de qualquer página, reduzindo o custo da tarefa mais frequente (registrar transação).
6. **Mobile bem tratado** — alvos de toque de 44px, gestos de swipe (`mobile-gestures.js`), `@media (hover: none)` para ações específicas de toque, PWA manifest.
7. **Dark mode automático** via `prefers-color-scheme` em todos os CSS principais.
8. **Acessibilidade básica presente** — link "Skip to content", `aria-label`/`aria-expanded`/`role="menu"` na navegação, ícones decorativos com `aria-hidden`.
9. **Fluxos de orçamento de baixo atrito** — edição inline do valor orçado (clique na célula), botões contextuais "Cover" para estouro e "Move" para realocar, banner de overspending no topo do dashboard.
10. **Sistema de mensagens estruturado** (`includes/error-handler.php`) com tipos, ícones e auto-dismiss de 5s para sucesso/info.

---

## 3. Problemas Encontrados

### 🔴 Críticos

#### U1. Sete de dez relatórios são órfãos na navegação
**Heurística:** Visibilidade / Reconhecimento em vez de memorização
**Evidência:** `public/reports/` contém 10 relatórios (spending-by-category, net-worth, income-vs-expense, age-of-money, category-trends, installments, installment-impact, budget, cash-flow-projection, what-if-projection). O dropdown "Reports" da navbar lista apenas 3 (Cash Flow, What-If, Projected Events — `includes/header.php:129-138`); o item "Reports" da sidebar aponta direto para `cash-flow-projection.php` (`header.php:212`). Os relatórios não se cross-linkam entre si (verificado por grep).
**Impacto:** funcionalidades inteiras (patrimônio líquido, gastos por categoria, idade do dinheiro) são inalcançáveis sem digitar a URL.
**Recomendação:** criar um hub `reports/index.php` com cards para todos os relatórios e/ou abas compartilhadas entre as páginas de relatório; apontar sidebar e navbar para o hub.
**Status:** ✅ **Resolvido em 2026-06-12** (commit `ae9825b`) — hub `reports/index.php` criado com cards para os 10 relatórios; sidebar e dropdown da navbar apontam para o hub.

#### U2. Idioma e moeda fixos (inglês / US$) sem localização
**Heurística:** Correspondência entre o sistema e o mundo real
**Evidência:** toda a UI está em inglês hardcoded; `formatCurrency()` retorna `'$' . number_format($cents / 100, 2)` (`config/database.php:54-56`, duplicada em `includes/email/EmailService.php:337`). O campo de valor até aceita vírgula decimal ("0.00 or 0,00" em `transactions/add.php:281`), mas a exibição é sempre formato americano.
**Impacto:** para usuários brasileiros (público real do projeto), todos os valores aparecem com símbolo e separadores errados — em um app financeiro, isso mina a confiança nos números.
**Recomendação:** configuração de moeda/locale por ledger (símbolo + `NumberFormatter` do PHP intl); centralizar `formatCurrency` num único lugar; planejar i18n das strings (mesmo que a primeira entrega seja só pt-BR/en).
**Status:** ✅ **Resolvido em 2026-06-12** (commit `f8f1cbb`) — moeda por ledger (USD/BRL/EUR/GBP) em `api.ledgers.metadata->>'currency'`; `formatCurrency` centralizado em `includes/currency.php` (mapa próprio, sem extensão intl) e `public/js/currency.js` + `window.PGB_CURRENCY` injetado no header; seletor em `ledgers/create.php` e em Settings; sweep completo dos formatadores hardcoded. Pendências: i18n das strings da UI e moeda nos e-mails de cron (seguem USD até o EmailService receber o ledger).

#### U3. Navegação duplicada e dependente do parâmetro `?ledger=` na URL
**Heurística:** Consistência e padrões / Prevenção de erros
**Evidência:** navbar superior e sidebar oferecem conjuntos sobrepostos mas diferentes de links (navbar tem Categories e Credit Cards; sidebar tem Projected Events em "Plan"). Ambas só renderizam os links contextuais quando `$_GET['ledger']` ou `$ledger_uuid` existe (`header.php:85,105,191`).
**Impacto:** duas hierarquias para aprender; se o usuário chega a uma página sem o parâmetro (link compartilhado, refresh em página de erro), o menu "encolhe" sem explicação — desorientação clássica.
**Recomendação:** guardar o ledger corrente na sessão como fallback do parâmetro GET; consolidar a navegação em uma única fonte (sidebar como primária, navbar reduzida a busca + quick add + usuário).
**Status:** ✅ **Resolvido em 2026-06-12** — `pgb_current_ledger()` (`config/database.php`) resolve o ledger por `?ledger=` → `$ledger_uuid` da página → fallback `$_SESSION['current_ledger']`, gravando o último ledger visto na sessão; todas as páginas `public/*` que liam `$_GET['ledger']` passaram a usá-la (sweep de ~55 arquivos), e `delete-ledger.php` esquece o ledger da sessão ao apagá-lo. A navbar foi reduzida a busca + Add + usuário/logout e a **sidebar é agora a única fonte de navegação** (Categories e Credit Cards migrados para ela; dropdowns "Finances"/"Reports" da navbar removidos). O ledger corrente é exposto em `<body data-ledger-uuid>` para que scripts (quick add, atalhos de teclado) sobrevivam a URLs sem `?ledger=`.

#### U4. Erro técnico de banco exposto ao usuário
**Heurística:** Ajudar usuários a reconhecer e se recuperar de erros
**Evidência:** `budget/dashboard.php:172` — `$_SESSION['error'] = 'Database error: ' . $e->getMessage();` — exibe a mensagem crua do PDO, apesar de existir `handleDatabaseError()` com mensagens amigáveis em `error-handler.php:96`. `display_errors` também está ligado na própria página (`dashboard.php:3-5`).
**Impacto:** mensagens incompreensíveis para o usuário e vazamento de detalhes internos (nomes de tabelas, SQL).
**Recomendação:** padronizar `handleDatabaseError()` em todas as páginas; remover `ini_set('display_errors', 1)` de código de produção.
**Status:** ✅ **Resolvido em 2026-06-12** (commit `ae9825b`) — páginas e endpoints da API logam via `error_log` e exibem mensagem genérica; removidos `display_errors`, vazamentos de stack trace e chaves `debug` no JSON; deletado o endpoint de debug `api/ledger-data-debug.php`. Exceções P0001 (validação do banco) seguem exibidas ao usuário, por design.

### 🟠 Altos

#### U5. Diálogos nativos convivendo com modais customizados
**Evidência:** 178 ocorrências de `alert(` e 17 de `confirm(` nativos em `js/` e `public/`, apesar de existirem `confirm-modal.php`/`confirm-modal.js` e o sistema de mensagens.
**Impacto:** experiência inconsistente (visual nativo do browser vs. design kit), `alert()` bloqueia a thread e não respeita dark mode/mobile.
**Recomendação:** sweep para substituir por `ConfirmModal`/toasts; lint rule ou grep no CI para impedir regressão.
**Status:** ✅ **Resolvido em 2026-06-13** — novo helper `Toast` (`public/js/toast.js`) reusa o markup/CSS `.message` do sistema de flash do servidor e substitui **todos os ~150 `alert()`**; tipo (success/error/warning/info) inferido do conteúdo, com `Toast.flash()` (via `sessionStorage`) para mensagens que precisam sobreviver a um `reload`/redirect. Todos os **18 `confirm()`** migraram para `ConfirmModal`: links/forms usam o atributo `data-confirm` (handler de `main.js`, agora respeitando validação HTML5 nativa via `reportValidity()`/`requestSubmit()`); funções JS usam `ConfirmModal.show({onConfirm})` ou, em fluxos `async`, um wrapper `Promise`. Restam 0 `alert(`/`confirm(` nativos fora de `vendor/`.

#### U6. Formulário de transação monolítico e sobrecarregado
**Evidência:** `transactions/add.php` tem **2.763 linhas** (HTML + CSS + JS inline) com 4 toggles opcionais empilhados: pagamento de empréstimo, vínculo a conta/obrigação, plano de parcelamento e split — todos visíveis na mesma página.
**Impacto:** sobrecarga cognitiva no fluxo mais frequente do app; manutenção difícil (o CSS/JS inline não passa pelo design kit nem pelo cache).
**Recomendação:** o Quick Add modal já cobre o caso comum — tratar `add.php` como "formulário avançado" e mover cada toggle para seção recolhida (progressive disclosure); extrair CSS/JS para arquivos versionados.

#### U7. Terminologia inconsistente
**Evidência:** "Ledger" e "Budget" usados de forma intercambiável ("No budget specified", botão "Delete" no card do ledger, label "Ledger" na sidebar); "Bills" no menu mas URL e código falam "Obligations"; saudação "Hello, `user_id`!" exibe o identificador interno em vez do nome (`header.php:150`).
**Impacto:** o usuário precisa aprender dois nomes para o mesmo conceito; já sinalizado como Issue #2 (60%) no plano de usabilidade — segue incompleto.
**Recomendação:** glossário único (sugestão: "Budget" na UI, "ledger" só no código; "Bills" na UI) e exibir nome de exibição do usuário.
**Status:** 🟡 **Parcialmente resolvido em 2026-06-13** — a saudação e o card de usuário agora exibem o **nome real** do usuário (`pgb_display_name()` em `config/database.php` busca `first_name` e cacheia na sessão; fallback para o username) em vez do identificador interno; o rótulo "Ledger" da sidebar virou "Budget" (o menu já usava "Bills" para obligations). Pendente: sweep completo do glossário "Budget vs Ledger" nas strings remanescentes (ex.: "No budget specified") e i18n.

#### U8. `<title>` estático em todas as páginas
**Evidência:** `header.php:13` fixa `PgBudget - Zero-Sum Budgeting` para o site inteiro.
**Impacto:** abas do navegador, histórico e favoritos ficam indistinguíveis; afeta também leitores de tela (o título é o primeiro anúncio da página).
**Recomendação:** aceitar `$page_title` antes do include do header: `<title><?= $page_title ?? 'PgBudget' ?> — PgBudget</title>`.
**Status:** ✅ **Resolvido em 2026-06-12** (commit `ae9825b`) — `$page_title` explícito por página, com fallback automático por seção da URL no `header.php`.

#### U9. Dependência de CDN `unpkg` com versão flutuante
**Evidência:** `header.php:24-29` carrega Popper, Tippy e **`lucide@latest`** do unpkg.
**Impacto:** o app se declara PWA, mas todos os ícones e tooltips quebram offline ou se o unpkg sair do ar; `@latest` pode introduzir breaking changes silenciosamente (os ícones são criados via `lucide.createIcons()` — se falhar, a navegação fica sem ícones).
**Recomendação:** servir as três libs localmente com versão pinada (são pequenas) e referenciá-las com o mesmo esquema de cache-busting `?v=` já usado no CSS.
**Status:** ✅ **Resolvido em 2026-06-12** (commit `ae9825b`) — Popper 2.11.8, Tippy 6.3.7, Lucide 0.525.0 e Chart.js 4.4.0 vendorizados em `js/vendor/`/`css/vendor/`; zero referências a unpkg/jsdelivr.

### 🟡 Médios

#### U10. Estilos inline pervasivos pós design kit
`budget/dashboard.php` (e outras páginas) ainda carrega dezenas de `style="..."` inline (ex.: linhas 217, 265-310) misturados às classes do kit. Risco de divergência visual conforme o kit evoluir. Recomendação: promover os padrões repetidos (hero stats, grids) a classes utilitárias.

#### U11. Ação destrutiva proeminente na home
O card de cada ledger na home exibe "🗑️ Delete" no mesmo nível de "Open Budget" (`public/index.php:82-90`). Há modal de confirmação, mas a ação mais destrutiva do sistema não deveria ter a mesma proeminência da ação primária. Recomendação: mover para menu overflow ("⋯") ou para Settings do ledger.
**Status:** ✅ **Resolvido em 2026-06-13** — cada card de budget na home agora tem um menu overflow ("⋯") no cabeçalho (`.card-menu` em `public/index.php`, estilos em `components.css`); a ação "🗑️ Delete budget" saiu da linha de ações primárias (que ficou só com "Open Budget" + "Accounts") e foi para dentro desse menu (`role="menu"`, fecha em clique externo/`Escape`). A confirmação via `delete-ledger.js` segue intacta (mesma classe `delete-ledger-btn`).

#### U12. Dark mode sem toggle manual
Só segue `prefers-color-scheme`; usuários que preferem tema diferente do SO não têm escolha. Recomendação: toggle em Settings persistido (localStorage + classe no `<html>`).
**Status:** ✅ **Resolvido em 2026-06-13** — os 11 blocos `@media (prefers-color-scheme: dark)` dos CSS foram convertidos para `:root[data-theme="dark"] { … }` (CSS nesting), e o `data-theme` passa a ser a única fonte da verdade. Um script inline no `<head>` (`includes/header.php`) resolve o tema **antes** do CSS aplicar (evita flash): preferência salva (`localStorage 'pgb-theme'`) → senão segue o SO; também reage a mudanças do SO enquanto a preferência é "auto". Settings → Appearance tem um seletor **Light / Auto / Dark** (`pgbSetTheme()`/`pgbGetThemePref()`). Cache-busting `?v=` bumpado para `20260613a`.

#### U13. Busca de transações limitada
`transactions/list.php:54-57` busca apenas `description ILIKE`. Não encontra por valor, payee ou intervalo de valores — buscas comuns em conciliação ("onde está aquele lançamento de R$ 1.234?"). Recomendação: incluir payee no LIKE e aceitar busca numérica por valor.
**Status:** ✅ **Resolvido em 2026-06-13** — a busca de `transactions/list.php` agora cobre `description`, `o.payee_name` e `o.name` (via `LEFT JOIN data.obligation_payments`/`data.obligations`); quando o termo é numérico, normaliza separadores BR (`1.234,56`) e US (`1,234.56`) e casa contra `ABS(t.amount)` em cents com tolerância de ±1 cent. Placeholder atualizado para "Search description, payee or amount…".

#### U14. Auto-dismiss de mensagens em 5s fixos
`header.php:36-58` remove mensagens success/info após 5s independentemente do tamanho do texto. Mensagens longas (ex.: resultado do onboarding) podem sumir antes de lidas. Recomendação: tempo proporcional ao texto (~50ms/caractere, mínimo 4s) e pausar no hover.
**Status:** ✅ **Resolvido em 2026-06-13** — o auto-dismiss agora calcula o tempo proporcional ao texto (`~50ms/char`, mínimo 4s, máximo 15s) e pausa a contagem enquanto o ponteiro está sobre a mensagem (`mouseenter`/`mouseleave`), retomando o tempo restante.

#### U15. Lacunas de acessibilidade pontuais
- Células editáveis do orçamento (`budget-amount-editable`) são `<td>` clicáveis sem `tabindex`/`role="button"` — inacessíveis por teclado (a navegação `j/k` dos shortcuts mitiga parcialmente, mas só para quem a conhece).
- Ícones de categoria são emojis sem rótulo textual alternativo.
- Vários botões dependem apenas de `title` para contexto (não anunciado de forma confiável por leitores de tela).
Recomendação: `tabindex="0"` + handler de Enter nas células editáveis; `aria-label` nos botões icon-only.
**Status:** 🟡 **Parcialmente resolvido em 2026-06-13** — as células editáveis do orçamento (`.budget-amount-editable`) agora são focáveis (`tabindex="0"`, `role="button"`, `aria-label` com o nome da categoria) e abrem o editor inline via **Enter/Espaço** (`public/js/budget-inline-edit.js`), com estilo de foco visível (`:focus-visible`) em `components.css`. Pendente: rótulos textuais alternativos para os emojis de categoria e revisão dos botões icon-only que dependem só de `title`.

---

## 4. Priorização Sugerida

| # | Item | Esforço | Impacto | Status |
|---|---|---|---|---|
| 1 | U1 — Hub de relatórios | Baixo (1 página + 2 links) | Alto | ✅ 2026-06-12 (`ae9825b`) |
| 2 | U4 — Sanear mensagens de erro + display_errors | Baixo | Alto | ✅ 2026-06-12 (`ae9825b`) |
| 3 | U8 — Títulos de página dinâmicos | Baixo | Médio | ✅ 2026-06-12 (`ae9825b`) |
| 4 | U9 — Vendorizar libs do unpkg | Baixo | Médio | ✅ 2026-06-12 (`ae9825b`) |
| 5 | U2 — Moeda/locale configurável | Médio | Alto | ✅ 2026-06-12 (`f8f1cbb`) — moeda; i18n de strings pendente |
| 6 | U3 — Ledger na sessão + navegação única | Médio | Alto | ✅ 2026-06-12 |
| 7 | U5 — Substituir alert/confirm nativos | Médio (mecânico) | Médio | ✅ 2026-06-13 |
| 8 | U7 — Glossário de terminologia | Médio | Médio | 🟡 Parcial 2026-06-13 (nome de usuário + label) |
| 9 | U6 — Refatorar add.php com progressive disclosure | Alto | Médio | Pendente |
| 10a | U13/U14 — Busca de transações + auto-dismiss | Baixo | Baixo-Médio | ✅ 2026-06-13 |
| 10b | U11 — Mover ação destrutiva para overflow | Baixo | Baixo-Médio | ✅ 2026-06-13 |
| 10c | U12 — Toggle de dark mode | Baixo | Médio | ✅ 2026-06-13 |
| 10d | U15 — Acessibilidade (células editáveis) | Baixo | Baixo-Médio | 🟡 Parcial 2026-06-13 |
| 10e | U10 — Estilos inline pós design kit | Baixo | Baixo | Pendente |

**Quick wins da semana:** ~~itens 1–4~~ ✅ concluídos em 2026-06-12, junto com o item 5 (U2) e o item 6 (U3 — navegação única + ledger na sessão). Em 2026-06-13 foram concluídos: item 7 (U5 — sweep alert/confirm), U13 (busca de transações), U14 (auto-dismiss proporcional), U11 (ação destrutiva no overflow) e U12 (toggle de dark mode); U7 e U15 ficaram **parciais** (nome de usuário + label de "Budget"; células editáveis acessíveis por teclado). Resta o i18n de strings remanescente de U2, o item 9 (U6), o glossário completo de U7, U10 e os complementos de U15.

---

## 5. Observações de Método

- Avaliação feita por inspeção estática de código (sem sessão de teste com usuários nem renderização das páginas); medidas como contraste de cores e tempos de resposta não foram verificadas.
- Contagens (`alert()`: 178, `confirm()`: 17) obtidas por grep em `js/` e `public/` em 2026-06-11.
- Os planos anteriores (`USABILITY_IMPROVEMENT_PLAN.md`, `UX_MODERNIZATION_PLAN.md`) continuam válidos; esta avaliação confirma que onboarding (Issue #1) e modernização mobile foram entregues, enquanto simplificação de linguagem (Issue #2) e consolidação de navegação seguem pendentes.
