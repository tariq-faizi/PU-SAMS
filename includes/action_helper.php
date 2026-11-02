<?php

if (!function_exists('currentActionMode')) {
    
    function currentActionMode(): string {
        $m = $_GET['mode'] ?? 'view';
        return in_array($m, ['view','edit','delete'], true) ? $m : 'view';
    }
}

if (!function_exists('renderActionDropdown')) {
    
    function renderActionDropdown(array $opt = []): string {
        $addUrl    = $opt['add_url']   ?? '?action=add';
        $baseUrl   = $opt['base_url']  ?? '?';
        $showAdd   = $opt['show_add']    ?? true;
        $showEdit  = $opt['show_edit']   ?? true;
        $showDel   = $opt['show_delete'] ?? true;
        $extra     = $opt['extra_query'] ?? ''; 
        $mode      = currentActionMode();

        $sep = (strpos($baseUrl, '?') === false) ? '?' : '&';
        $editUrl   = $baseUrl . $sep . 'mode=edit'   . ($extra ? '&'.ltrim($extra,'&') : '');
        $deleteUrl = $baseUrl . $sep . 'mode=delete' . ($extra ? '&'.ltrim($extra,'&') : '');
        $viewUrl   = $baseUrl . $sep . 'mode=view'   . ($extra ? '&'.ltrim($extra,'&') : '');

        $btnClass = match ($mode) {
            'edit'   => 'btn-warning',
            'delete' => 'btn-danger',
            default  => 'btn-primary',
        };
        $modeLabel = match ($mode) {
            'edit'   => '✏️ Edit Mode',
            'delete' => '🗑️ Delete Mode',
            default  => '⚙️ کړنې',
        };

        $html  = '<div class="action-dropdown-wrap d-flex align-items-center gap-2">';
        
        if ($mode !== 'view') {
            $html .= '<a href="'.h($viewUrl).'" class="btn btn-sm btn-outline-secondary" title="Back to view mode"><i class="fas fa-times me-1"></i>Close Mode</a>';
        }
        $html .= '<div class="dropdown">';
        $html .= '<button class="btn btn-sm '.$btnClass.' dropdown-toggle action-main-btn" type="button" data-bs-toggle="dropdown" aria-expanded="false">';
        $html .= '<i class="fas fa-bolt me-1"></i>'.$modeLabel;
        $html .= '</button>';
        $html .= '<ul class="dropdown-menu dropdown-menu-end shadow-lg action-dropdown-menu">';
        if ($showAdd) {
            $html .= '<li><a class="dropdown-item action-item-add" href="'.h($addUrl).'"><i class="fas fa-plus me-2 text-success"></i>  Add new</a></li>';
        }
        if ($showEdit) {
            $cls = $mode === 'edit' ? 'active' : '';
            $html .= '<li><a class="dropdown-item action-item-edit '.$cls.'" href="'.h($editUrl).'"><i class="fas fa-edit me-2 text-warning"></i>  Edit</a></li>';
        }
        if ($showDel) {
            $cls = $mode === 'delete' ? 'active' : '';
            $html .= '<li><a class="dropdown-item action-item-delete '.$cls.'" href="'.h($deleteUrl).'"><i class="fas fa-trash me-2 text-danger"></i> Inactive & delete</a></li>';
        }
        $html .= '<li><hr class="dropdown-divider"></li>';
        $html .= '<li><a class="dropdown-item small text-muted" href="'.h($viewUrl).'"><i class="fas fa-eye me-2"></i>View</a></li>';
        $html .= '</ul>';
        $html .= '</div></div>';
        return $html;
    }
}

if (!function_exists('renderModeBanner')) {
    
    function renderModeBanner(): string {
        $mode = currentActionMode();
        if ($mode === 'view') return '';
        if ($mode === 'edit') {
            return '<div class="alert alert-warning d-flex align-items-center gap-2 py-2 mode-banner mode-edit">'
                 . '<i class="fas fa-pen-to-square"></i>'
                 . '<strong>Edit Mode</strong>'
                 . '</div>';
        }
        return '<div class="alert alert-danger d-flex align-items-center gap-2 py-2 mode-banner mode-delete">'
             . '<i class="fas fa-triangle-exclamation"></i>'
             . '<strong>Delete Mode</strong>'
             . '</div>';
    }
}

if (!function_exists('renderRowActions')) {
    
    function renderRowActions(array $opt): string {
        $mode = currentActionMode();
        $viewUrl  = $opt['view_url']    ?? '';
        $viewLabel= $opt['view_label']  ?? 'View';
        $editUrl  = $opt['edit_url']    ?? '';
        $delForm  = $opt['delete_form'] ?? '';

        $out = '<div class="d-flex gap-1 flex-wrap row-actions">';
        if ($viewUrl !== '') {
            $out .= '<a href="'.h($viewUrl).'" class="btn btn-sm btn-outline-info" title="View"><i class="fas fa-eye"></i> '.h($viewLabel).'</a>';
        }
        if ($mode === 'edit' && $editUrl !== '') {
            $out .= '<a href="'.h($editUrl).'" class="btn btn-sm btn-warning" title="edit"><i class="fas fa-edit"></i> edit</a>';
        }
        if ($mode === 'delete' && $delForm !== '') {
            $out .= $delForm;
        }
        $out .= '</div>';
        return $out;
    }
}

if (!function_exists('preserveQuery')) {
    
    function preserveQuery(array $extra = []): string {
        $current = $_GET;
        unset($current['mode']);
        $merged  = array_merge($current, $extra);
        return http_build_query($merged);
    }
}
