        </div> <!-- End Container -->
    </main> <!-- End Main Content -->

    <!-- Mobile Bottom Navigation -->
    <nav class="bottom-nav">
        <a href="<?= BASE_URL ?>/pages/dashboard.php" class="nav-item <?= $current_page == 'dashboard.php' ? 'active' : '' ?>">
            <i class="fas fa-chart-pie nav-icon"></i>
            <span>Home</span>
        </a>
        <a href="<?= BASE_URL ?>/pages/job_orders.php" class="nav-item <?= $current_page == 'job_orders.php' ? 'active' : '' ?>">
            <i class="fas fa-file-invoice nav-icon"></i>
            <span>Jobs</span>
        </a>
        <a href="<?= BASE_URL ?>/pages/transactions.php" class="nav-item <?= $current_page == 'transactions.php' ? 'active' : '' ?>">
            <i class="fas fa-wallet nav-icon"></i>
            <span>Transact</span>
        </a>
        <a href="<?= BASE_URL ?>/pages/reports.php" class="nav-item <?= $current_page == 'reports.php' ? 'active' : '' ?>">
            <i class="fas fa-chart-line nav-icon"></i>
            <span>Reports</span>
        </a>
        <a href="<?= BASE_URL ?>/pages/settings.php" class="nav-item <?= $current_page == 'settings.php' ? 'active' : '' ?>">
            <i class="fas fa-cog nav-icon"></i>
            <span>Settings</span>
        </a>
    </nav>

    <!-- Custom Scripts -->
    <script>
        // Simple script to format Naira or handle UI resets on page load
        const NAIRA_SYMBOL = "₦";
        
        document.addEventListener('DOMContentLoaded', () => {
            document.querySelectorAll('.currency-naira').forEach(el => {
                if(!el.innerHTML.includes(NAIRA_SYMBOL)) {
                    let val = parseFloat(el.innerHTML.replace(/,/g, ''));
                    if(!isNaN(val)) {
                        el.innerHTML = NAIRA_SYMBOL + val.toLocaleString('en-NG', {minimumFractionDigits: 2});
                    }
                }
            });
        });
    </script>
</body>
</html>
