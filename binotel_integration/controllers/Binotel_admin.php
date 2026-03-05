<?php
defined('BASEPATH') or exit('No direct script access allowed');

class Binotel_admin extends AdminController {

    public function __construct() {
        parent::__construct();
        $this->load->model('binotel_integration/Binotel_integration_model');
    }

    public function index() {
        $data['title'] = 'Binotel Integration';
        $this->load->view('binotel_integration/view_admin', $data);
    }
    
    
     public function binotel_notifications() {
        $data['title'] = 'Усі дзвінки Binotel';

        // Отримання параметрів фільтрації з GET-запиту
        $start_date = $this->input->get('start_date');
        $end_date   = $this->input->get('end_date');

        // Фільтрація дзвінків за датою
        $this->db->order_by('date', 'DESC');
        if (!empty($start_date) && !empty($end_date)) {
            $this->db->where('date >=', $start_date . ' 00:00:00');
            $this->db->where('date <=', $end_date . ' 23:59:59');
        }
        $data['notifications'] = $this->db->get(db_prefix().'binotel_notifications')->result();

        // Підрахунок нових лідів за період
        if (!empty($start_date) && !empty($end_date)) {
            $this->db->where('dateadded >=', $start_date . ' 00:00:00');
            $this->db->where('dateadded <=', $end_date . ' 23:59:59');
            $data['new_leads_count'] = $this->db->count_all_results(db_prefix().'leads');
        } else {
            $data['new_leads_count'] = 0;
        }

        // Готуємо дані для графіків, якщо період обрано
        if (!empty($start_date) && !empty($end_date)) {
            // Графік дзвінків від лідів (виключаємо записи, що містять "клієнт" або "клієнта")
            $this->db->select('description, COUNT(*) as count');
            $this->db->where('date >=', $start_date . ' 00:00:00');
            $this->db->where('date <=', $end_date . ' 23:59:59');
            $this->db->not_like('description', 'клієнт');
            $this->db->not_like('description', 'клієнта');
            $this->db->group_by('description');
            $data['calls_per_lead'] = $this->db->get(db_prefix().'binotel_notifications')->result();

            // Графік дзвінків від клієнтів
            $this->db->select('description, COUNT(*) as count');
            $this->db->where('date >=', $start_date . ' 00:00:00');
            $this->db->where('date <=', $end_date . ' 23:59:59');
            $this->db->like('description', 'Вхідний дзвінок від клієнта');
            $this->db->group_by('description');
            $data['calls_per_client'] = $this->db->get(db_prefix().'binotel_notifications')->result();
        } else {
            $data['calls_per_lead']   = [];
            $data['calls_per_client'] = [];
        }

        // Для кожного сповіщення підтягуємо recording_link із таблиць статистики
        foreach ($data['notifications'] as &$note) {
            $phone = '';
            $additional = @unserialize($note->additional_data);
            if (is_array($additional) && !empty($additional[0])) {
                $phone = trim($additional[0]);
            }
            // Якщо номер знайдено, шукаємо recording_link у таблицях (спершу у лідів, потім у клієнтів, потім у співробітників)
            if (!empty($phone)) {
                $recordingLink = $this->find_recording_link_by_phone($phone, 'leads');
                if (!$recordingLink) {
                    $recordingLink = $this->find_recording_link_by_phone($phone, 'clients');
                }
                if (!$recordingLink) {
                    $recordingLink = $this->find_recording_link_by_phone($phone, 'staff');
                }
                $note->recording_link = $recordingLink ? $recordingLink : '';
            } else {
                $note->recording_link = '';
            }
        }
        unset($note);

        // Відображаємо повний шаблон
        $this->load->view('binotel_integration/all_notifications', $data);
    }

    /**
     * Метод пошуку recording_link за номером телефону у заданій таблиці
     * @param string $phone
     * @param string $type - 'leads', 'clients', 'staff'
     * @return string|null
     */
    private function find_recording_link_by_phone($phone, $type = 'leads') {
        $tables = [
            'leads'   => db_prefix().'binotel_call_statistics_leads',
            'clients' => db_prefix().'binotel_call_statistics_clients',
            'staff'   => db_prefix().'binotel_call_statistics_staff',
        ];
        if (!isset($tables[$type])) {
            return null;
        }
        $tableName = $tables[$type];
        $this->db->select('recording_link');
        $this->db->where('contact_name', $phone);
        $this->db->order_by('call_time', 'DESC');
        $this->db->limit(1);
        $row = $this->db->get($tableName)->row();
        return ($row && !empty($row->recording_link)) ? $row->recording_link : null;
    }

    public function get_binotel_calls_list() {
    $this->db->order_by('date', 'DESC');
    $this->db->limit(10);
    $calls = $this->db->get(db_prefix().'binotel_notifications')->result();

    $hasNewCalls = false;
    $newCallColor = '';
    $html = '';

    if ($calls) {
        foreach ($calls as $call) {
            if ($call->isread == 0) {
                $hasNewCalls = true;
                if (empty($newCallColor)) {
                    if (strpos($call->description, 'color:green') !== false) {
                        $newCallColor = 'green';
                    } elseif (strpos($call->description, 'color:blue') !== false) {
                        $newCallColor = 'blue';
                    } elseif (strpos($call->description, 'color:red') !== false) {
                        $newCallColor = 'red';
                    } elseif (strpos($call->description, 'color:orange') !== false) {
                        $newCallColor = 'orange';
                    }
                }
            }

            // Дістаємо номер телефону
            $additional = @unserialize($call->additional_data);
            $phone = (is_array($additional) && isset($additional[0])) ? $additional[0] : '';

            // Починаємо <li>
            $html .= '<li class="relative notification-wrapper" data-notification-id="'.$call->id.'">';
            $html .= '  <div class="tw-p-3 notification-box" style="display: flex; justify-content: space-between; align-items: flex-start;">';

            // Ліва частина: посилання на $call->link
            $html .= '    <a href="'.admin_url($call->link).'" class="notification-top notification-link" '
                  .'style="text-decoration: none; color: inherit; flex: 1; margin-right: 10px;">';
            $html .= '      <div class="media-body">';
            $html .= '        <span class="notification-title">'.$call->description.'</span><br>';
            $html .= '        <span class="notification-date">'.date("d.m.Y H:i", strtotime($call->date)).'</span>';
            $html .= '      </div>';
            $html .= '    </a>';

            // Права частина: кнопка виклику, якщо є номер
            if (!empty($phone)) {
                // Кнопка з атрибутом title="Телефонувати"
                $html .= '    <button class="call-button" data-phone="'.$phone.'" title="Телефонувати" '
                       .'style="background: #fff; border: 1px solid #000; border-radius: 50%; '
                       .'width: 30px; height: 30px; display: flex; align-items: center; '
                       .'justify-content: center; cursor: pointer;">'
                       .'<i class="fa fa-phone" style="color: #000; font-size: 16px;"></i>'
                       .'</button>';
            }

            $html .= '  </div>'; 
            $html .= '</li>';
        }
    } else {
        $html .= '<li><div class="tw-p-3">Нема дзвінків</div></li>';
    }

    // (Опційно) Кнопка «Набрати номер»
    $html .= '
        <li class="divider !tw-my-0"></li>
        <li class="relative notification-wrapper">
            <div class="tw-p-3 notification-box">
                <div class="media-body tw-text-center">
                    <button class="dialpad-floating-btn" onclick="openDialpad(); return false;" title="Набрати номер">
                        <i class="fa fa-th"></i>
                    </button>
                </div>
            </div>
        </li>
    ';

    // Кнопка "Подивитися всі дзвінки"
    $html .= '
        <li class="divider !tw-my-0"></li>
        <div class="tw-text-center tw-p-4 tw-bg-neutral-50">
            <a class="btn btn-default" href="'.admin_url('binotel_integration/binotel_admin/binotel_notifications').'">
                Подивитися всі дзвінки
            </a>
        </div>
    ';

    echo json_encode([
        'html'         => $html,
        'hasNew'       => $hasNewCalls,
        'newCallColor' => $newCallColor,
    ]);
}




public function mark_binotel_notifications_read() {
    $this->db->set('isread', 1);
    $this->db->update(db_prefix().'binotel_notifications');
    echo json_encode(['status' => 'success']);
}

/**
 * Транскрибація запису розмови через OpenAI Whisper API
 * POST параметри: call_id (int), call_type (leads|clients|staff)
 */
public function transcribe_call() {
    if (!$this->input->is_ajax_request()) {
        show_404();
    }

    header('Content-Type: application/json');

    $csrf = [
        'csrf_name' => $this->security->get_csrf_token_name(),
        'csrf_hash' => $this->security->get_csrf_hash(),
    ];

    $call_id   = (int) $this->input->post('call_id');
    $call_type = $this->input->post('call_type');

    $allowed_types = ['leads', 'clients', 'staff'];
    if (!$call_id || !in_array($call_type, $allowed_types)) {
        echo json_encode(['success' => false, 'error' => 'Невірні параметри'] + $csrf);
        return;
    }

    $openai_api_key = get_option('binotel_openai_api_key');
    if (empty($openai_api_key)) {
        echo json_encode(['success' => false, 'error' => 'OpenAI API ключ не налаштовано. Будь ласка, вкажіть його в налаштуваннях Binotel.'] + $csrf);
        return;
    }

    $tables = [
        'leads'   => db_prefix() . 'binotel_call_statistics_leads',
        'clients' => db_prefix() . 'binotel_call_statistics_clients',
        'staff'   => db_prefix() . 'binotel_call_statistics_staff',
    ];
    $table = $tables[$call_type];

    $row = $this->db->get_where($table, ['id' => $call_id])->row();
    if (!$row) {
        echo json_encode(['success' => false, 'error' => 'Запис не знайдено'] + $csrf);
        return;
    }

    // Якщо транскрипція вже існує — повертаємо її
    if (!empty($row->transcription)) {
        echo json_encode(['success' => true, 'transcription' => $row->transcription] + $csrf);
        return;
    }

    if (empty($row->recording_link)) {
        echo json_encode(['success' => false, 'error' => 'Запис розмови відсутній'] + $csrf);
        return;
    }

    $api_key    = get_option('binotel_api_key');
    $api_secret = get_option('binotel_secret');

    // Визначаємо generalCallID: з БД → з URL → через пошук в Binotel API
    $general_call_id = $row->general_call_id ?? '';
    if (empty($general_call_id) && !empty($row->recording_link)) {
        if (preg_match('/[\/=](\d{6,15})\/?$/', $row->recording_link, $m)) {
            $general_call_id = $m[1];
        }
    }
    if (empty($general_call_id) && !empty($api_key) && !empty($api_secret)) {
        $general_call_id = $this->_lookup_general_call_id(
            $row->contact_name ?? '',
            $row->call_time ?? '',
            $api_key,
            $api_secret
        );
        // Зберігаємо знайдений ID щоб не шукати знову
        if (!empty($general_call_id)) {
            $this->db->where('id', $call_id);
            $this->db->update($table, ['general_call_id' => $general_call_id]);
        }
    }

    $tmp_file   = tempnam(sys_get_temp_dir(), 'binotel_rec_') . '.mp3';
    $audio_data = false;

    // Стратегія 1: Binotel API з generalCallID
    if (!empty($general_call_id) && !empty($api_key) && !empty($api_secret)) {
        $audio_data = $this->_download_via_binotel_api($general_call_id, $api_key, $api_secret);
    }

    // Стратегія 2: пряме завантаження
    if ($audio_data === false || $this->_is_html($audio_data)) {
        $audio_data = $this->_download_file($row->recording_link);
    }

    if ($audio_data === false) {
        $debug = 'general_call_id=' . ($general_call_id ?: 'порожній') . ', api_key=' . (!empty($api_key) ? 'є' : 'відсутній');
        echo json_encode(['success' => false, 'error' => 'Не вдалося завантажити аудіозапис. (' . $debug . ')'] + $csrf);
        return;
    }

    // Перевіряємо, що отримали аудіо, а не HTML
    if ($this->_is_html($audio_data)) {
        $debug = 'general_call_id=' . ($general_call_id ?: 'порожній') . ', api_key=' . (!empty($api_key) ? 'є' : 'відсутній');
        echo json_encode(['success' => false, 'error' => 'Сервер Binotel повернув HTML замість аудіо. URL запису потребує авторизованого сеансу браузера. (' . $debug . ')'] + $csrf);
        return;
    }

    file_put_contents($tmp_file, $audio_data);

    // Відправляємо на OpenAI Whisper
    $transcription = $this->_transcribe_with_whisper($tmp_file, $openai_api_key);
    @unlink($tmp_file);

    if ($transcription === false) {
        echo json_encode(['success' => false, 'error' => 'Помилка транскрибації. Перевірте OpenAI API ключ та спробуйте ще раз.'] + $csrf);
        return;
    }

    // Зберігаємо транскрипцію в БД
    $this->db->where('id', $call_id);
    $this->db->update($table, ['transcription' => $transcription]);

    echo json_encode(['success' => true, 'transcription' => $transcription] + $csrf);
}

/**
 * Шукає generalCallID через Binotel API get-calls-list.json за номером телефону та часом дзвінка.
 * Використовується як fallback коли webhook не надіслав generalCallID.
 *
 * @param string $phone        Номер телефону (зовнішній)
 * @param string $call_time    Datetime рядок дзвінка (Y-m-d H:i:s)
 * @param string $api_key
 * @param string $api_secret
 * @return string|null  generalCallID або null якщо не знайдено
 */
private function _lookup_general_call_id($phone, $call_time, $api_key, $api_secret) {
    if (empty($phone) || empty($call_time)) {
        return null;
    }

    $ts        = strtotime($call_time);
    $date_from = date('Y-m-d H:i:s', $ts - 300);  // ±5 хв
    $date_to   = date('Y-m-d H:i:s', $ts + 300);
    $phone_clean = preg_replace('/\D/', '', $phone);

    $body = json_encode([
        'key'      => $api_key,
        'secret'   => $api_secret,
        'dateFrom' => $date_from,
        'dateTo'   => $date_to,
    ]);

    $ch = curl_init('https://api.binotel.com/api/4.0/calls/get-calls-list.json');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST,           true);
    curl_setopt($ch, CURLOPT_POSTFIELDS,     $body);
    curl_setopt($ch, CURLOPT_HTTPHEADER,     ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_TIMEOUT,        30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

    $response  = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false || $http_code !== 200) {
        return null;
    }

    $data = @json_decode($response, true);
    if (!is_array($data)) {
        return null;
    }

    // Binotel повертає масив дзвінків; шукаємо за номером телефону
    $calls = $data['calls'] ?? $data['data'] ?? (isset($data[0]) ? $data : []);
    foreach ($calls as $call) {
        $ext = preg_replace('/\D/', '', $call['externalNumber'] ?? $call['phone'] ?? '');
        if ($ext === $phone_clean || str_ends_with($ext, $phone_clean) || str_ends_with($phone_clean, $ext)) {
            $gid = $call['generalCallID'] ?? $call['id'] ?? null;
            if (!empty($gid)) {
                return (string) $gid;
            }
        }
    }

    return null;
}

/**
 * Перевіряє чи є вміст HTML-документом
 */
private function _is_html($data) {
    if (empty($data)) return false;
    $start = strtolower(substr(ltrim($data), 0, 15));
    return strpos($start, '<html') !== false || strpos($start, '<!doc') !== false;
}

/**
 * Завантажує аудіозапис через Binotel API /calls/get-record.json
 * @param string $general_call_id
 * @param string $api_key
 * @param string $api_secret
 * @return string|false - бінарний вміст аудіофайлу або false при помилці
 */
private function _download_via_binotel_api($general_call_id, $api_key, $api_secret) {
    $url  = 'https://api.binotel.com/api/4.0/calls/get-record.json';
    $body = json_encode([
        'key'           => $api_key,
        'secret'        => $api_secret,
        'generalCallID' => $general_call_id,
    ]);

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

    $data      = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($data === false || $http_code !== 200) {
        return false;
    }

    // API може повернути JSON з посиланням на файл або сам файл
    $decoded = @json_decode($data, true);
    if (is_array($decoded)) {
        // Шукаємо URL запису у відомих полях відповіді Binotel
        $record_url = $decoded['recordUrl']
            ?? $decoded['url']
            ?? $decoded['link']
            ?? $decoded['fileUrl']
            ?? $decoded['record']
            ?? null;
        if ($record_url) {
            return $this->_download_file($record_url);
        }
        return false;
    }

    // Якщо відповідь — бінарні дані аудіо (перевіряємо що не HTML)
    if ($this->_is_html($data)) {
        return false;
    }

    return $data;
}

/**
 * Завантажує файл за URL та повертає його вміст
 * @param string $url
 * @return string|false
 */
private function _download_file($url) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    $data = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($data === false || $http_code !== 200) {
        return false;
    }
    return $data;
}

/**
 * Транскрибує аудіофайл через OpenAI Whisper API
 * @param string $file_path - шлях до тимчасового аудіофайлу
 * @param string $api_key   - OpenAI API ключ
 * @return string|false     - текст транскрипції або false при помилці
 */
private function _transcribe_with_whisper($file_path, $api_key) {
    $post_fields = [
        'file'     => new CURLFile($file_path, 'audio/mpeg', 'recording.mp3'),
        'model'    => 'whisper-1',
        'language' => 'uk',
    ];

    $ch = curl_init('https://api.openai.com/v1/audio/transcriptions');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post_fields);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $api_key,
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 120);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

    $response  = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false || $http_code !== 200) {
        return false;
    }

    $result = json_decode($response, true);
    if (!isset($result['text'])) {
        return false;
    }
    return $result['text'];
}


}
