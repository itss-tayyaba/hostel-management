<?php
declare(strict_types=1);
/**
 * @file includes/layout/footer.php
 * @brief Dashboard footer — Closes layout, loads main.js and ui.js
 * @access layout
 */
?>
</div> <!-- dash-main -->
</div> <!-- dash-shell -->

<script src="<?php echo e(base_path() . '/assets/js/main.js'); ?>"></script>
<script src="<?php echo e(base_path() . '/assets/js/ui.js'); ?>"></script>
<script>
  // Close sidebar when overlay is clicked (mobile).
  var overlay = document.querySelector('.dash-overlay');
  if (overlay) {
    overlay.addEventListener('click', function () {
      var sidebar = document.getElementById('dashSidebar');
      if (sidebar) sidebar.classList.remove('open');
      overlay.classList.remove('open');
    });
  }
</script>
</body>
</html>

