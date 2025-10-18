const SpendingReport = {
    ledgerUuid: '',
    startDate: '',
    endDate: '',
    chart: null,
    chartType: 'doughnut',
    spendingData: [],

    async init() {
        await this.loadData();
        this.renderChart();
        this.renderTable();
        this.setupEventListeners();
    },

    setupEventListeners() {
        document.getElementById('toggle-chart-type').addEventListener('click', () => {
            this.toggleChartType();
        });
    },

    async loadData() {
        try {
            const summaryUrl = `../api/get-spending-report.php?action=summary&ledger=${this.ledgerUuid}&start_date=${this.startDate}&end_date=${this.endDate}`;
            const summaryResp = await fetch(summaryUrl);
            const summaryData = await summaryResp.json();

            if (summaryData.success && summaryData.summary) {
                this.renderSummary(summaryData.summary);
            }

            const spendingUrl = `../api/get-spending-report.php?action=spending&ledger=${this.ledgerUuid}&start_date=${this.startDate}&end_date=${this.endDate}`;
            const spendingResp = await fetch(spendingUrl);
            const spendingData = await spendingResp.json();
            
            if (spendingData.success) {
                this.spendingData = spendingData.data;
            }
        } catch (error) {
            console.error('Error loading data:', error);
            alert('Error loading report data');
        }
    },

    renderSummary(summary) {
        document.getElementById('total-spending').textContent = this.formatCurrency(summary.total_spending);
        document.getElementById('category-count').textContent = summary.category_count;
        document.getElementById('transaction-count').textContent = summary.transaction_count;
        document.getElementById('largest-category').textContent = summary.largest_category_name || '-';
    },

    renderChart() {
        const ctx = document.getElementById('spendingChart').getContext('2d');
        
        if (this.chart) {
            this.chart.destroy();
        }

        const labels = this.spendingData.map(d => d.category_name);
        const data = this.spendingData.map(d => d.total_spent / 100);
        const colors = this.generateColors(this.spendingData.length);

        this.chart = new Chart(ctx, {
            type: this.chartType,
            data: {
                labels: labels,
                datasets: [{
                    label: 'Spending',
                    data: data,
                    backgroundColor: colors,
                    borderWidth: 2,
                    borderColor: '#fff'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'right'
                    },
                    tooltip: {
                        callbacks: {
                            label: (context) => {
                                const label = context.label || '';
                                const value = this.formatCurrency(context.parsed.y || context.parsed * 100);
                                const percent = this.spendingData[context.dataIndex].percentage;
                                return `${label}: ${value} (${percent}%)`;
                            }
                        }
                    }
                },
                onClick: (event, elements) => {
                    if (elements.length > 0) {
                        const index = elements[0].index;
                        const category = this.spendingData[index];
                        this.showTransactions(category);
                    }
                }
            }
        });
    },

    renderTable() {
        let tableHtml = '<table class="table"><thead><tr><th>Category</th><th>Total Spent</th><th>Transactions</th><th>% of Total</th><th>Actions</th></tr></thead><tbody>';
        
        this.spendingData.forEach(row => {
            const rowJson = JSON.stringify(row);
            const escapedJson = rowJson.replace(/'/g, "\\'");
            tableHtml += `
                <tr>
                    <td><strong>${row.category_name}</strong></td>
                    <td class="amount negative">${this.formatCurrency(row.total_spent)}</td>
                    <td>${row.transaction_count}</td>
                    <td>${row.percentage}%</td>
                    <td>
                        <button class="btn btn-small btn-secondary" onclick='SpendingReport.showTransactions(${escapedJson})'>
                            View Transactions
                        </button>
                    </td>
                </tr>
            `;
        });
        
        tableHtml += '</tbody></table>';
        document.getElementById('spending-table').innerHTML = tableHtml;
    },

    async showTransactions(category) {
        document.getElementById('modal-category-name').textContent = category.category_name;
        document.getElementById('transactionModal').classList.add('active');
        document.getElementById('modal-transactions').innerHTML = '<p class="loading-text">Loading transactions...</p>';

        try {
            const url = `../api/get-spending-report.php?action=transactions&category=${category.category_uuid}&start_date=${this.startDate}&end_date=${this.endDate}`;
            const resp = await fetch(url);
            const data = await resp.json();

            if (data.success) {
                let html = '<table class="table"><thead><tr><th>Date</th><th>Description</th><th>Account</th><th>Amount</th></tr></thead><tbody>';
                
                data.transactions.forEach(t => {
                    const date = new Date(t.transaction_date).toLocaleDateString();
                    html += `
                        <tr>
                            <td>${date}</td>
                            <td>${t.description}</td>
                            <td>${t.other_account_name}</td>
                            <td class="amount negative">${this.formatCurrency(t.amount)}</td>
                        </tr>
                    `;
                });
                
                html += '</tbody></table>';
                document.getElementById('modal-transactions').innerHTML = html;
            }
        } catch (error) {
            console.error('Error loading transactions:', error);
            document.getElementById('modal-transactions').innerHTML = '<p class="error-text">Error loading transactions</p>';
        }
    },

    toggleChartType() {
        this.chartType = this.chartType === 'doughnut' ? 'bar' : 'doughnut';
        document.getElementById('chart-type-label').textContent = 
            this.chartType === 'doughnut' ? 'Switch to Bar Chart' : 'Switch to Donut Chart';
        document.getElementById('chart-type-icon').textContent = 
            this.chartType === 'doughnut' ? '📊' : '🍩';
        this.renderChart();
    },

    generateColors(count) {
        const hueStep = 360 / count;
        const colors = [];
        for (let i = 0; i < count; i++) {
            colors.push(`hsl(${i * hueStep}, 70%, 60%)`);
        }
        return colors;
    },

    formatCurrency(cents) {
        return new Intl.NumberFormat('en-US', {
            style: 'currency',
            currency: 'USD'
        }).format(cents / 100);
    }
};

function closeModal() {
    document.getElementById('transactionModal').classList.remove('active');
}

function setDateRange(period) {
    const form = document.querySelector('.date-filter-form');
    const today = new Date();
    let startDate, endDate;

    switch(period) {
        case 'this-month':
            startDate = new Date(today.getFullYear(), today.getMonth(), 1);
            endDate = new Date(today.getFullYear(), today.getMonth() + 1, 0);
            break;
        case 'last-month':
            startDate = new Date(today.getFullYear(), today.getMonth() - 1, 1);
            endDate = new Date(today.getFullYear(), today.getMonth(), 0);
            break;
        case 'last-3-months':
            startDate = new Date(today.getFullYear(), today.getMonth() - 2, 1);
            endDate = new Date(today.getFullYear(), today.getMonth() + 1, 0);
            break;
        case 'ytd':
            startDate = new Date(today.getFullYear(), 0, 1);
            endDate = today;
            break;
    }

    document.getElementById('start_date').value = startDate.toISOString().split('T')[0];
    document.getElementById('end_date').value = endDate.toISOString().split('T')[0];
    form.submit();
}
