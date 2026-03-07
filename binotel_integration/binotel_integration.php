<?php

defined('BASEPATH') or exit('No direct script access allowed');

/*
Module Name:   <img src="https://perfex.vikna-city.com.ua/modules/binotel_integration/binotel.png" >   Binotel Integration   
Description: Integration with Binotel telephony for Perfex CRM   <i class="fa fa-phone"></i> .
Version: 1.0.0
Requires at least: 2.3.*
*/
define('BINOTEL_INTEGRATION_MODULE_NAME', 'binotel_integration');

hooks()->add_action('admin_init', 'binotel_integration_init_menu_items');
register_activation_hook('binotel_integration', 'binotel_integration_activation');
register_deactivation_hook('binotel_integration', 'binotel_integration_deactivation');
register_uninstall_hook('binotel_integration', 'binotel_integration_uninstall');

function binotel_integration_activation() {
    $CI = &get_instance();
    require_once(__DIR__ . '/install.php');
}

function binotel_integration_deactivation() {
    // Код для деактивації модуля
}

function binotel_integration_uninstall() {
    // Код для видалення модуля
    $CI = &get_instance();
    $CI->db->query('DROP TABLE IF EXISTS `' . db_prefix() . 'binotel_call_statistics_clients`');
    $CI->db->query('DROP TABLE IF EXISTS `' . db_prefix() . 'binotel_call_statistics_leads`');
}

register_language_files(BINOTEL_INTEGRATION_MODULE_NAME, [BINOTEL_INTEGRATION_MODULE_NAME]);

function binotel_integration_init_menu_items() {
    $CI = &get_instance();

    $CI->app_menu->add_sidebar_menu_item('binotel-menu-item', [
        'name'     => 'Binotel Integration',
        'href'     => admin_url('binotel_integration/binotel_admin'),
        'position' => 45,
        'icon'     => 'fa fa-phone',
    ]);
}

hooks()->add_action('admin_init', 'binotel_integration_hook_admin_init');

/**
 * Додавання пункту в налаштування
 */
function binotel_integration_hook_admin_init() {
    $CI = &get_instance();

    if (is_admin() || has_permission('settings', '', 'view')) {
        $perfex_version = (int)$CI->app->get_current_db_version();
        $slug = 'binotel_integration_settings';
        $tab = [
            'name'     => 'Binotel Integration',
            'view'     => 'binotel_integration/binotel_settings',
            'position' => 40, // Позиція у вкладках
            'icon'     => 'fa fa-phone', // Іконка для вкладки
        ];

        // Додавання вкладки до налаштувань
        if ($perfex_version >= 320) {
            $CI->app->add_settings_section_child('integrations', $slug, $tab);
        } else {
            $CI->app_tabs->add_settings_tab($slug, $tab);
        }
    }
}


hooks()->add_action('admin_init', 'binotel_integration_add_customers_menu_items');

function binotel_integration_add_customers_menu_items() {
    $CI = &get_instance();

    if (has_permission('customers', '', 'view')) {
        $CI->app_tabs->add_customer_profile_tab('call_statistics', [
            'name'     => 'Статистика розмов',
            'icon'     => 'fa fa-phone',
            'view'     => 'binotel_integration/call_statistics',
            'position' => 20,
        ]);
    }
}



// Для Лідів
hooks()->add_action('after_lead_lead_tabs', 'binotel_integration_add_call_statistics_tab_first_lead');

function binotel_integration_add_call_statistics_tab_first_lead($lead)
{
    if (!isset($lead->id)) {
        return; // Якщо об'єкт $lead не ініціалізований, виходимо з функції
    }

    // Додаємо вкладку "Статистика розмов" на початок
    echo '<li role="presentation">
        <a href="#tab_call_statistics" aria-controls="tab_call_statistics" role="tab" data-toggle="tab"> 
           <i class="fa-solid fa-mobile-retro"></i> '.  _l('call_statistics') . '
        </a>
    </li>';
}

hooks()->add_action('after_lead_tabs_content', 'binotel_integration_add_call_statistics_tab_content_lead');

function binotel_integration_add_call_statistics_tab_content_lead($lead)
{
    if (!isset($lead->id)) {
        return; // Якщо об'єкт $lead не ініціалізований, виходимо з функції
    }
    
    echo '<div role="tabpanel" class="tab-pane" id="tab_call_statistics">';
    $CI = &get_instance();
    $CI->load->view('binotel_integration/lead_call_statistics', ['lead' => $lead]);
    echo '</div>';
}

  // Додавання вкладки статистика ромов для співробітників
hooks()->add_action('app_admin_head', 'binotel_integration_add_staff_tab_js');

function binotel_integration_add_staff_tab_js() {
    echo '<script>
        document.addEventListener("DOMContentLoaded", function() {
            var tabsList = document.querySelector(".nav-tabs");

            if (tabsList) {
                // Отримання staff_id з прихованого поля або DOM
                var staffIdElement = document.querySelector(\'input[name="memberid"]\');
                if (!staffIdElement) {
                    console.error("ID співробітника не знайдено.");
                    return;
                }
                var staffId = staffIdElement.value;

                // Додати нову вкладку
                var newTab = document.createElement("li");
                newTab.innerHTML = `<a href="#tab_call_statistics" data-toggle="tab"><i class="fa fa-phone"></i> Статистика розмов</a>`;
                tabsList.appendChild(newTab);

                // Додати контейнер для вкладки
                var tabContent = document.querySelector(".tab-content");
                if (tabContent) {
                    var newTabContent = document.createElement("div");
                    newTabContent.className = "tab-pane";
                    newTabContent.id = "tab_call_statistics";
                    newTabContent.innerHTML = `<div id="call-statistics-content">Завантаження...</div>`;
                    tabContent.appendChild(newTabContent);

                    // Завантажити вміст через AJAX при переході на вкладку
                    document.querySelector("a[href=\'#tab_call_statistics\']").addEventListener("click", function () {
                        if (!newTabContent.dataset.loaded) {
                            fetch("' . admin_url('binotel_integration/load_staff_call_statistics') . '?staff_id=" + staffId)
                                .then(response => response.text())
                                .then(html => {
                                    document.getElementById("call-statistics-content").innerHTML = html;
                                    newTabContent.dataset.loaded = "true";
                                })
                                .catch(error => {
                                    console.error("Помилка завантаження статистики розмов:", error);
                                    document.getElementById("call-statistics-content").innerHTML = "Помилка завантаження вмісту.";
                                });
                        }
                    });
                }
            }
        });
    </script>';
}

hooks()->add_action('app_admin_head', 'binotel_integration_insert_floating_icon_view');
function binotel_integration_insert_floating_icon_view(){
    $CI = &get_instance();
    $CI->load->view('binotel_integration/floating_icon');
}

/**
 * Повертає JavaScript для кнопок транскрибації записів розмов.
 * Виводиться один раз на сторінці.
 */
function binotel_transcription_js() {
    static $rendered = false;
    if ($rendered) {
        return '';
    }
    $rendered = true;

    $CI = &get_instance();
    $transcribe_url = admin_url('binotel_integration/binotel_admin/transcribe_call');
    $delete_url     = admin_url('binotel_integration/binotel_admin/delete_transcription');
    $csrf_name      = $CI->security->get_csrf_token_name();
    $csrf_hash      = $CI->security->get_csrf_hash();
    ob_start();
    ?>
<style>
.binotel-transcription-dialog {
    max-width: 420px;
    max-height: 220px;
    overflow-y: auto;
    border: 1px solid #dce3ea;
    border-radius: 6px;
    padding: 6px 8px;
    background: #f8fafc;
    font-size: 12px;
    margin-bottom: 4px;
}
.binotel-dialog-line {
    display: flex;
    gap: 6px;
    padding: 4px 0;
    border-bottom: 1px solid #edf1f5;
    line-height: 1.5;
}
.binotel-dialog-line:last-child { border-bottom: none; }
.binotel-dialog-ts {
    color: #aaa;
    font-size: 11px;
    white-space: nowrap;
    min-width: 38px;
    padding-top: 1px;
    font-family: monospace;
}
.binotel-dialog-text { color: #333; flex: 1; }
.binotel-dialog-plain { color: #333; white-space: pre-wrap; flex: 1; }
.binotel-btn-row { margin-top: 4px; }
.binotel-btn-row .btn { margin-right: 4px; }
</style>
<script>
(function() {
    var csrfName = '<?php echo $csrf_name; ?>';
    var csrfHash = '<?php echo $csrf_hash; ?>';

    function escHtml(s) {
        return String(s)
            .replace(/&/g,'&amp;').replace(/</g,'&lt;')
            .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    function renderDialog(container, transcription) {
        var segs;
        try { segs = JSON.parse(transcription); } catch(e) { segs = null; }
        if (Array.isArray(segs) && segs.length) {
            container.innerHTML = segs.map(function(s) {
                return '<div class="binotel-dialog-line">' +
                    '<span class="binotel-dialog-ts">' + escHtml(s.t) + '</span>' +
                    '<span class="binotel-dialog-text">' + escHtml(s.text) + '</span>' +
                    '</div>';
            }).join('');
        } else {
            container.innerHTML = '<div class="binotel-dialog-line">' +
                '<span class="binotel-dialog-plain">' + escHtml(transcription) + '</span>' +
                '</div>';
        }
    }

    function resetBtn(btn) {
        if (!btn) return;
        btn.disabled = false;
        btn.innerHTML = btn.classList.contains('binotel-retranscribe-btn')
            ? '<i class="fa fa-refresh"></i>'
            : '<i class="fa fa-file-text-o"></i> Транскрибувати';
    }

    function showTranscription(wrapper, transcription, btn) {
        var dialog = wrapper.querySelector('.binotel-transcription-dialog');
        if (!dialog) {
            dialog = document.createElement('div');
            dialog.className = 'binotel-transcription-dialog';
            wrapper.insertBefore(dialog, wrapper.firstChild);
        }
        renderDialog(dialog, transcription);

        var btnRow = wrapper.querySelector('.binotel-btn-row');
        if (!btnRow) {
            btnRow = document.createElement('div');
            btnRow.className = 'binotel-btn-row';
            btnRow.innerHTML =
                '<button class="btn btn-xs btn-default binotel-retranscribe-btn" title="Транскрибувати повторно"><i class="fa fa-refresh"></i></button>' +
                '<button class="btn btn-xs btn-danger binotel-delete-transcription-btn" title="Видалити транскрипцію"><i class="fa fa-trash"></i></button>';
            if (btn) { btn.replaceWith(btnRow); }
            else { wrapper.appendChild(btnRow); }
        }
    }

    function handleServerResponse(r, btn, wrapper) {
        return r.text().then(function(text) {
            if (!text) throw new Error('Порожня відповідь сервера.');
            var data;
            try { data = JSON.parse(text); } catch(e) { throw new Error('Невірна відповідь сервера.'); }
            if (data.csrf_hash) { csrfHash = data.csrf_hash; }
            if (data.success) {
                showTranscription(wrapper, data.transcription, btn);
            } else {
                alert('Помилка транскрибації: ' + (data.error || 'Невідома помилка'));
                resetBtn(btn);
            }
        });
    }

    function sendBlob(blob, callId, callType, btn, wrapper, ext) {
        btn && (btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Транскрибація...');
        var formData = new FormData();
        formData.append('call_id', callId);
        formData.append('call_type', callType);
        formData.append(csrfName, csrfHash);
        formData.append('audio_blob', blob, 'recording.' + (ext || 'webm'));
        fetch('<?php echo $transcribe_url; ?>', {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: formData
        })
        .then(function(r) { return handleServerResponse(r, btn, wrapper); })
        .catch(function(err) {
            alert('Помилка: ' + err.message);
            resetBtn(btn);
        });
    }

    function doTranscribe(wrapper) {
        var callId       = wrapper.getAttribute('data-call-id');
        var callType     = wrapper.getAttribute('data-call-type');
        var recordingUrl = wrapper.getAttribute('data-recording-url');
        var btn = wrapper.querySelector('.binotel-transcribe-btn, .binotel-retranscribe-btn');
        if (btn) {
            btn.disabled = true;
            btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Завантаження...';
        }

        if (recordingUrl) {
            fetch(recordingUrl, { credentials: 'include' })
                .then(function(r) {
                    if (!r.ok) throw new Error('http-' + r.status);
                    var ct = r.headers.get('Content-Type') || '';
                    if (ct.indexOf('html') !== -1) throw new Error('got-html');
                    return r.blob();
                })
                .then(function(blob) {
                    if (blob.size < 1000) throw new Error('too-small');
                    sendBlob(blob, callId, callType, btn, wrapper, 'mp3');
                })
                .catch(function() {
                    resetBtn(btn);
                    showFileUpload(wrapper, callId, callType, true);
                });
            return;
        }

        resetBtn(btn);
        showFileUpload(wrapper, callId, callType, false);
    }

    function showFileUpload(wrapper, callId, callType, autoDownload) {
        if (wrapper.querySelector('.binotel-file-upload-area')) return;

        var recordingUrl = wrapper.getAttribute('data-recording-url');

        if (autoDownload && recordingUrl) {
            var a = document.createElement('a');
            a.href = recordingUrl;
            a.download = '';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
        }

        var area = document.createElement('div');
        area.className = 'binotel-file-upload-area';
        area.style.cssText = 'margin-top:6px;padding:8px;background:#fff8e1;border:1px solid #ffe082;border-radius:4px;font-size:12px;';
        area.innerHTML = autoDownload
            ? '<div style="margin-bottom:4px;color:#555;">Файл завантажується у браузері — оберіть його після завантаження:</div>' +
              '<label style="cursor:pointer;margin:0;display:inline-block;">' +
              '<span class="btn btn-xs btn-primary"><i class="fa fa-upload"></i> Обрати файл</span>' +
              '<input type="file" accept="audio/*" style="display:none;" class="binotel-audio-file-input">' +
              '</label>'
            : '<label style="cursor:pointer;margin:0;display:inline-block;">' +
              '<span class="btn btn-xs btn-default"><i class="fa fa-upload"></i> Обрати MP3/WAV для транскрибації</span>' +
              '<input type="file" accept="audio/*" style="display:none;" class="binotel-audio-file-input">' +
              '</label>';

        wrapper.appendChild(area);

        area.querySelector('.binotel-audio-file-input').addEventListener('change', function() {
            var file = this.files[0];
            if (!file) return;
            area.remove();
            var btn = wrapper.querySelector('.binotel-transcribe-btn, .binotel-retranscribe-btn');
            sendBlob(file, callId, callType, btn, wrapper, file.name.split('.').pop() || 'mp3');
        });
    }

    document.addEventListener('click', function(e) {
        // Видалення транскрипції
        var delBtn = e.target.closest('.binotel-delete-transcription-btn');
        if (delBtn) {
            if (!confirm('Видалити транскрипцію?')) return;
            var wrapper = delBtn.closest('.binotel-transcription-wrapper');
            if (!wrapper) return;
            var callId   = wrapper.getAttribute('data-call-id');
            var callType = wrapper.getAttribute('data-call-type');
            var formData = new FormData();
            formData.append('call_id', callId);
            formData.append('call_type', callType);
            formData.append(csrfName, csrfHash);
            delBtn.disabled = true;
            delBtn.innerHTML = '<i class="fa fa-spinner fa-spin"></i>';
            fetch('<?php echo $delete_url; ?>', {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: formData
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.csrf_hash) csrfHash = data.csrf_hash;
                if (data.success) {
                    var dialog = wrapper.querySelector('.binotel-transcription-dialog');
                    var btnRow = wrapper.querySelector('.binotel-btn-row');
                    if (dialog) dialog.remove();
                    var newBtn = document.createElement('button');
                    newBtn.className = 'btn btn-xs btn-primary binotel-transcribe-btn';
                    newBtn.innerHTML = '<i class="fa fa-file-text-o"></i> Транскрибувати';
                    if (btnRow) { btnRow.replaceWith(newBtn); }
                    else { wrapper.appendChild(newBtn); }
                } else {
                    delBtn.disabled = false;
                    delBtn.innerHTML = '<i class="fa fa-trash"></i>';
                }
            })
            .catch(function() {
                delBtn.disabled = false;
                delBtn.innerHTML = '<i class="fa fa-trash"></i>';
            });
            return;
        }

        // Транскрибація
        var btn = e.target.closest('.binotel-transcribe-btn, .binotel-retranscribe-btn');
        if (!btn) return;
        var wrapper = btn.closest('.binotel-transcription-wrapper');
        if (!wrapper) return;
        doTranscribe(wrapper);
    });
})();
</script>
    <?php
    return ob_get_clean();
}

