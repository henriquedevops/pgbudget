const IncomeExpenseReport = {
    ledgerUuid: document.querySelector('input[name="ledger"]').value,
    startDate: document.querySelector('input[name="start_date"]').value,
    endDate: document.querySelector('input[name="end_date"]').value,
    chart: null,
    monthlyData: [],
    summary: null,

    async init() {
        await this.loadData();
        this.renderChart();
        this.renderInsights();
    },

    async loadData() {
        try {
            const summaryResp = await fetch(\`../api/get-income-expense-report.php?action=summary&ledger=\${this.ledgerUuid}&start_date=\${this.startDate}&end_date=\${this.endDate}\`);
            const summaryData = await summaryResp.json();

            if (summaryData.success && summaryData.summary) {
                this.summary = summaryData.summary;
                this.renderSummary(summaryData.summary);
            }

            const monthlyResp = await fetch(\`../api/get-income-expense-report.php?action=monthly&ledger=\${this.ledgerUuid}&start_date=\${this.startDate}&end_date=\${this.endDate}\`);
            const monthlyData = await monthlyResp.json();

            if (monthlyData.success) {
                this.monthlyData = monthlyData.data;
            }
        } catch (error) {
            console.error('Error loading data:', error);
            alert('Error loading report data');
        }
    },

    renderSummary(summary) {
        document.getElementById('total-income').textContent = this.formatCurrency(summary.total_income);
        document.getElementById('total-expense').textContent = this.formatCurrency(summary.total_expense);
        document.getElementById('net-total').textContent = this.formatCurrency(summary.net_total);
        document.getElementById('savings-rate').textContent = summary.overall_savings_rate + '%';

        document.getElementById('avg-income').textContent = this.formatCurrency(summary.average_monthly_income);
        document.getElementById('avg-expense').textContent = this.formatCurrency(summary.average_monthly_expense);
        document.getElementById('avg-net').textContent = this.formatCurrency(summary.average_monthly_net);

        document.getElementById('surplus-months').textContent = summary.surplus_months;
        document.getElementById('deficit-months').textContent = summary.deficit_months;

        const netCard = document.querySelector('.net-card');
        if (summary.net_total > 0) {
            netCard.style.background = 'linear-gradient(135deg, #38a169 0%, #48bb78 100%)';
        } else if (summary.net_total < 0) {
            netCard.style.background = 'linear-gradient(135deg, #fc8181 0%, #f56565 100%)';
        }
    },

    renderChart() {
        const ctx = document.getElementById('incomeExpenseChart').getContext('2d');

        if (this.chart) {
            this.chart.destroy();
        }

        const labels = this.monthlyData.map(d => d.month_name);
        const incomeData = this.monthlyData.map(d => d.total_income / 100);
        const expenseData = this.monthlyData.map(d => d.total_expense / 100);
        const netData = this.monthlyData.map(d => d.net / 100);

        this.chart = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [
                    {
                        label: 'Income',
                        data: incomeData,
                        backgroundColor: 'rgba(72, 187, 120, 0.8)',
                        borderColor: 'rgb(72, 187, 120)',
                        borderWidth: 2
                    },
                    {
                        label: 'Expenses',
                        data: expenseData,
                        backgroundColor: 'rgba(245, 101, 101, 0.8)',
                        borderColor: 'rgb(245, 101, 101)',
                        borderWidth: 2
                    },
                    {
                        label: 'Net',
                        data: netData,
                        type: 'line',
                        backgroundColor: 'rgba(66, 153, 225, 0.2)',
                        borderColor: 'rgb(66, 153, 225)',
                        borderWidth: 3,
                        fill: false,
                        tension: 0.4,
                        pointRadius: 5,
                        pointHoverRadius: 7
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: {
                    mode: 'index',
                    intersect: false
                },
                plugins: {
                    legend: {
                        position: 'top',
                        labels: {
                            font: {
                                size: 14
                            },
                            padding: 15
                        }
                    },
                    tooltip: {
                        callbacks: {
                            label: (context) => {
                                const label = context.dataset.label || '';
                                const value = IncomeExpenseReport.formatCurrency(context.parsed.y * 100);
                                if (context.datasetIndex === 2) {
                                    const rate = IncomeExpenseReport.monthlyData[context.dataIndex].savings_rate;
                                    return \`\${label}: \${value} (\${rate}% savings rate)\`;
                                }
                                return \`\${label}: \${value}\`;
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: (value) => {
                                return '$' + value.toLocaleString();
                            }
                        },
                        grid: {
                            color: 'rgba(0, 0, 0, 0.05)'
                        }
                    },
                    x: {
                        grid: {
                            display: false
                        }
                    }
                }
            }
        });
    },

    renderInsights() {
        if (!this.summary || this.monthlyData.length === 0) {
            document.getElementById('insights-content').innerHTML = '<p>Not enough data to generate insights.</p>';
            return;
        }

        const insights = [];

        if (this.summary.overall_savings_rate > 20) {
            insights.push('<li class="insight-positive">🎉 Excellent savings rate of ' + this.summary.overall_savings_rate + '%! You are saving more than 20% of your income.</li>');
        } else if (this.summary.overall_savings_rate > 10) {
            insights.push('<li class="insight-neutral">👍 Good savings rate of ' + this.summary.overall_savings_rate + '%. Aim for 20% or more to build wealth faster.</li>');
        } else if (this.summary.overall_savings_rate > 0) {
            insights.push('<li class="insight-warning">⚠️ Your savings rate is ' + this.summary.overall_savings_rate + '%. Try to increase it to at least 10% of your income.</li>');
        } else {
            insights.push('<li class="insight-negative">🚨 You are spending more than you earn! Focus on reducing expenses or increasing income.</li>');
        }

        if (this.summary.deficit_months > this.summary.surplus_months) {
            insights.push('<li class="insight-warning">📉 You had more deficit months (' + this.summary.deficit_months + ') than surplus months (' + this.summary.surplus_months + '). Review your budget to identify areas to cut.</li>');
        } else if (this.summary.surplus_months > 0) {
            insights.push('<li class="insight-positive">📈 You had ' + this.summary.surplus_months + ' surplus months! Keep up the good work.</li>');
        }

        if (this.monthlyData.length >= 3) {
            const recentMonths = this.monthlyData.slice(-3);
            const avgRecent = recentMonths.reduce((sum, m) => sum + parseInt(m.net), 0) / recentMonths.length;
            const olderMonths = this.monthlyData.slice(0, -3);
            const avgOlder = olderMonths.length > 0 ? olderMonths.reduce((sum, m) => sum + parseInt(m.net), 0) / olderMonths.length : 0;

            if (avgRecent > avgOlder && olderMonths.length > 0) {
                insights.push('<li class="insight-positive">📊 Your finances are trending upward! Recent months show improvement.</li>');
            } else if (avgRecent < avgOlder && olderMonths.length > 0) {
                insights.push('<li class="insight-warning">📊 Recent months show declining savings. Review what changed.</li>');
            }
        }

        const avgDiff = this.summary.average_monthly_income - this.summary.average_monthly_expense;
        if (avgDiff > 0) {
            insights.push('<li class="insight-neutral">💵 On average, you save ' + this.formatCurrency(avgDiff) + ' per month.</li>');
        }

        if (insights.length > 0) {
            document.getElementById('insights-content').innerHTML = '<ul>' + insights.join('') + '</ul>';
        } else {
            document.getElementById('insights-content').innerHTML = '<p>Track more data to get personalized insights.</p>';
        }
    },

    formatCurrency(cents) {
        return new Intl.NumberFormat('en-US', {
            style: 'currency',
            currency: 'USD'
        }).format(cents / 100);
    }
};

function setDateRange(period) {
    const form = document.querySelector('.date-filter-form');
    const today = new Date();
    let startDate, endDate = today;

    switch(period) {
        case 'last-3-months':
            startDate = new Date(today.getFullYear(), today.getMonth() - 3, 1);
            break;
        case 'last-6-months':
            startDate = new Date(today.getFullYear(), today.getMonth() - 6, 1);
            break;
        case 'last-12-months':
            startDate = new Date(today.getFullYear(), today.getMonth() - 12, 1);
            break;
        case 'ytd':
            startDate = new Date(today.getFullYear(), 0, 1);
            break;
        case 'all-time':
            startDate = new Date(2020, 0, 1);
            break;
    }

    document.getElementById('start_date').value = startDate.toISOString().split('T')[0];
    document.getElementById('end_date').value = endDate.toISOString().split('T')[0];
    form.submit();
}

document.addEventListener('DOMContentLoaded', () => {
    IncomeExpenseReport.init();
});
