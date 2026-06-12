# Avaliação de Usabilidade — PGBudget

> **Data:** 2026-06-11
> **Método:** Avaliação heurística (Nielsen) por inspeção de código — páginas públicas (`public/`), includes compartilhados (`includes/`), CSS/JS (`css/`, `js/`)
> **Complementa:** `USABILITY_IMPROVEMENT_PLAN.md`, `USABILITY_IMPROVEMENTS_STATUS.md`, `UX_MODERNIZATION_PLAN.md`
> **Escopo:** usabilidade da interface web; não cobre Telegram bot, e-mails ou API

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

#### U2. Idioma e moeda fixos (inglês / US$) sem localização
**Heurística:** Correspondência entre o sistema e o mundo real
**Evidência:** toda a UI está em inglês hardcoded; `formatCurrency()` retorna `'$' . number_format($cents / 100, 2)` (`config/database.php:54-56`, duplicada em `includes/email/EmailService.php:337`). O campo de valor até aceita vírgula decimal ("0.00 or 0,00" em `transactions/add.php:281`), mas a exibição é sempre formato americano.
**Impacto:** para usuários brasileiros (público real do projeto), todos os valores aparecem com símbolo e separadores errados — em um app financeiro, isso mina a confiança nos números.
**Recomendação:** configuração de moeda/locale por ledger (símbolo + `NumberFormatter` do PHP intl); centralizar `formatCurrency` num único lugar; planejar i18n das strings (mesmo que a primeira entrega seja só pt-BR/en).

#### U3. Navegação duplicada e dependente do parâmetro `?ledger=` na URL
**Heurística:** Consistência e padrões / Prevenção de erros
**Evidência:** navbar superior e sidebar oferecem conjuntos sobrepostos mas diferentes de links (navbar tem Categories e Credit Cards; sidebar tem Projected Events em "Plan"). Ambas só renderizam os links contextuais quando `$_GET['ledger']` ou `$ledger_uuid` existe (`header.php:85,105,191`).
**Impacto:** duas hierarquias para aprender; se o usuário chega a uma página sem o parâmetro (link compartilhado, refresh em página de erro), o menu "encolhe" sem explicação — desorientação clássica.
**Recomendação:** guardar o ledger corrente na sessão como fallback do parâmetro GET; consolidar a navegação em uma única fonte (sidebar como primária, navbar reduzida a busca + quick add + usuário).

#### U4. Erro técnico de banco exposto ao usuário
**Heurística:** Ajudar usuários a reconhecer e se recuperar de erros
**Evidência:** `budget/dashboard.php:172` — `$_SESSION['error'] = 'Database error: ' . $e->getMessage();` — exibe a mensagem crua do PDO, apesar de existir `handleDatabaseError()` com mensagens amigáveis em `error-handler.php:96`. `display_errors` também está ligado na própria página (`dashboard.php:3-5`).
**Impacto:** mensagens incompreensíveis para o usuário e vazamento de detalhes internos (nomes de tabelas, SQL).
**Recomendação:** padronizar `handleDatabaseError()` em todas as páginas; remover `ini_set('display_errors', 1)` de código de produção.

### 🟠 Altos

#### U5. Diálogos nativos convivendo com modais customizados
**Evidência:** 178 ocorrências de `alert(` e 17 de `confirm(` nativos em `js/` e `public/`, apesar de existirem `confirm-modal.php`/`confirm-modal.js` e o sistema de mensagens.
**Impacto:** experiência inconsistente (visual nativo do browser vs. design kit), `alert()` bloqueia a thread e não respeita dark mode/mobile.
**Recomendação:** sweep para substituir por `ConfirmModal`/toasts; lint rule ou grep no CI para impedir regressão.

#### U6. Formulário de transação monolítico e sobrecarregado
**Evidência:** `transactions/add.php` tem **2.763 linhas** (HTML + CSS + JS inline) com 4 toggles opcionais empilhados: pagamento de empréstimo, vínculo a conta/obrigação, plano de parcelamento e split — todos visíveis na mesma página.
**Impacto:** sobrecarga cognitiva no fluxo mais frequente do app; manutenção difícil (o CSS/JS inline não passa pelo design kit nem pelo cache).
**Recomendação:** o Quick Add modal já cobre o caso comum — tratar `add.php` como "formulário avançado" e mover cada toggle para seção recolhida (progressive disclosure); extrair CSS/JS para arquivos versionados.

#### U7. Terminologia inconsistente
**Evidência:** "Ledger" e "Budget" usados de forma intercambiável ("No budget specified", botão "Delete" no card do ledger, label "Ledger" na sidebar); "Bills" no menu mas URL e código falam "Obligations"; saudação "Hello, `user_id`!" exibe o identificador interno em vez do nome (`header.php:150`).
**Impacto:** o usuário precisa aprender dois nomes para o mesmo conceito; já sinalizado como Issue #2 (60%) no plano de usabilidade — segue incompleto.
**Recomendação:** glossário único (sugestão: "Budget" na UI, "ledger" só no código; "Bills" na UI) e exibir nome de exibição do usuário.

#### U8. `<title>` estático em todas as páginas
**Evidência:** `header.php:13` fixa `PgBudget - Zero-Sum Budgeting` para o site inteiro.
**Impacto:** abas do navegador, histórico e favoritos ficam indistinguíveis; afeta também leitores de tela (o título é o primeiro anúncio da página).
**Recomendação:** aceitar `$page_title` antes do include do header: `<title><?= $page_title ?? 'PgBudget' ?> — PgBudget</title>`.

#### U9. Dependência de CDN `unpkg` com versão flutuante
**Evidência:** `header.php:24-29` carrega Popper, Tippy e **`lucide@latest`** do unpkg.
**Impacto:** o app se declara PWA, mas todos os ícones e tooltips quebram offline ou se o unpkg sair do ar; `@latest` pode introduzir breaking changes silenciosamente (os ícones são criados via `lucide.createIcons()` — se falhar, a navegação fica sem ícones).
**Recomendação:** servir as três libs localmente com versão pinada (são pequenas) e referenciá-las com o mesmo esquema de cache-busting `?v=` já usado no CSS.

### 🟡 Médios

#### U10. Estilos inline pervasivos pós design kit
`budget/dashboard.php` (e outras páginas) ainda carrega dezenas de `style="..."` inline (ex.: linhas 217, 265-310) misturados às classes do kit. Risco de divergência visual conforme o kit evoluir. Recomendação: promover os padrões repetidos (hero stats, grids) a classes utilitárias.

#### U11. Ação destrutiva proeminente na home
O card de cada ledger na home exibe "🗑️ Delete" no mesmo nível de "Open Budget" (`public/index.php:82-90`). Há modal de confirmação, mas a ação mais destrutiva do sistema não deveria ter a mesma proeminência da ação primária. Recomendação: mover para menu overflow ("⋯") ou para Settings do ledger.

#### U12. Dark mode sem toggle manual
Só segue `prefers-color-scheme`; usuários que preferem tema diferente do SO não têm escolha. Recomendação: toggle em Settings persistido (localStorage + classe no `<html>`).

#### U13. Busca de transações limitada
`transactions/list.php:54-57` busca apenas `description ILIKE`. Não encontra por valor, payee ou intervalo de valores — buscas comuns em conciliação ("onde está aquele lançamento de R$ 1.234?"). Recomendação: incluir payee no LIKE e aceitar busca numérica por valor.

#### U14. Auto-dismiss de mensagens em 5s fixos
`header.php:36-58` remove mensagens success/info após 5s independentemente do tamanho do texto. Mensagens longas (ex.: resultado do onboarding) podem sumir antes de lidas. Recomendação: tempo proporcional ao texto (~50ms/caractere, mínimo 4s) e pausar no hover.

#### U15. Lacunas de acessibilidade pontuais
- Células editáveis do orçamento (`budget-amount-editable`) são `<td>` clicáveis sem `tabindex`/`role="button"` — inacessíveis por teclado (a navegação `j/k` dos shortcuts mitiga parcialmente, mas só para quem a conhece).
- Ícones de categoria são emojis sem rótulo textual alternativo.
- Vários botões dependem apenas de `title` para contexto (não anunciado de forma confiável por leitores de tela).
Recomendação: `tabindex="0"` + handler de Enter nas células editáveis; `aria-label` nos botões icon-only.

---

## 4. Priorização Sugerida

| # | Item | Esforço | Impacto |
|---|---|---|---|
| 1 | U1 — Hub de relatórios | Baixo (1 página + 2 links) | Alto |
| 2 | U4 — Sanear mensagens de erro + display_errors | Baixo | Alto |
| 3 | U8 — Títulos de página dinâmicos | Baixo | Médio |
| 4 | U9 — Vendorizar libs do unpkg | Baixo | Médio |
| 5 | U2 — Moeda/locale configurável | Médio | Alto |
| 6 | U3 — Ledger na sessão + navegação única | Médio | Alto |
| 7 | U5 — Substituir alert/confirm nativos | Médio (mecânico) | Médio |
| 8 | U7 — Glossário de terminologia | Médio | Médio |
| 9 | U6 — Refatorar add.php com progressive disclosure | Alto | Médio |
| 10 | U12/U13/U14/U15 — Melhorias incrementais | Baixo cada | Baixo-Médio |

**Quick wins da semana:** itens 1–4 são essencialmente um dia de trabalho combinados e eliminam dois críticos.

---

## 5. Observações de Método

- Avaliação feita por inspeção estática de código (sem sessão de teste com usuários nem renderização das páginas); medidas como contraste de cores e tempos de resposta não foram verificadas.
- Contagens (`alert()`: 178, `confirm()`: 17) obtidas por grep em `js/` e `public/` em 2026-06-11.
- Os planos anteriores (`USABILITY_IMPROVEMENT_PLAN.md`, `UX_MODERNIZATION_PLAN.md`) continuam válidos; esta avaliação confirma que onboarding (Issue #1) e modernização mobile foram entregues, enquanto simplificação de linguagem (Issue #2) e consolidação de navegação seguem pendentes.
