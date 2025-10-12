    </main>
    <footer class="footer">
        <div class="footer-content">
            <p>&copy; 2025 PgBudget - Zero-sum budgeting with double-entry accounting</p>
        </div>
    </footer>
    <?php
    // Include Quick-Add Modal for authenticated users
    if (isset($_SESSION['user_id'])) {
        require_once __DIR__ . '/quick-add-modal.php';
    }
    ?>
    <script src="/pgbudget/js/main.js"></script>
    <?php
    // Include Quick-Add Modal JS for authenticated users
    if (isset($_SESSION['user_id'])) {
        echo '<script src="/pgbudget/js/quick-add-modal.js"></script>';
    }
    ?>
</body>
</html>