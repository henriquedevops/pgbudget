<?php
function renderHelpIcon($tooltipText) {
    $tooltipHtml = htmlspecialchars($tooltipText, ENT_QUOTES, 'UTF-8');
    echo '<span class="help-icon" data-tippy-content="' . $tooltipHtml . '">(?)</span>';
}
?>