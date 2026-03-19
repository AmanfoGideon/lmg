<?php
$__zn_paths = [
  __DIR__."/../zenoph.notify-2.25.08-php/lib/Zenoph/Notify/AutoLoader.php",
  __DIR__."/../lib/Zenoph/Notify/AutoLoader.php"
];
foreach ($__zn_paths as $__p) { if (file_exists($__p)) { require_once $__p; break; } }
function _env($k, $def = '') {
  $v = getenv($k);
  if ($v === false || $v === '') {
    if (isset($_SERVER[$k]) && $_SERVER[$k] !== '') { $v = $_SERVER[$k]; }
    else if (isset($_ENV[$k]) && $_ENV[$k] !== '') { $v = $_ENV[$k]; }
    else if (defined($k) && constant($k) !== '') { $v = constant($k); }
    else {
      static $loaded = false; static $vars = [];
      if (!$loaded) {
        $paths = [
          __DIR__.'/.env',
          dirname(__DIR__).'/.env',
          dirname(__DIR__).'/includes.env',
          __DIR__.'/../includes.env'
        ];
        foreach ($paths as $p) {
          if (is_file($p)) {
            $lines = @file($p, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if (is_array($lines)) {
              foreach ($lines as $line) {
                if ($line === '' || $line[0] === '#') { continue; }
                $parts = explode('=', $line, 2);
                if (count($parts) === 2) {
                  $key = trim($parts[0]);
                  $val = trim($parts[1]);
                  if ($key !== '' && $val !== '') { $vars[$key] = $val; }
                }
              }
            }
          }
        }
        $loaded = true;
      }
      $v = isset($vars[$k]) && $vars[$k] !== '' ? $vars[$k] : $def;
    }
  }
  return $v;
}
use Zenoph\Notify\Enums\AuthModel;
use Zenoph\Notify\Enums\SMSType;
use Zenoph\Notify\Enums\DestinationStatus;
use Zenoph\Notify\Request\SMSRequest;
use Zenoph\Notify\Request\AuthRequest;
use Zenoph\Notify\Store\AuthProfile;

function ghana_number_formatter(string $phoneNumber): string {
  $raw = trim($phoneNumber);
  if ($raw === '') return $phoneNumber;
  $digits = preg_replace('/\D+/', '', $raw);
  if ($digits === '') return $phoneNumber;
  if (strpos($digits, '00') === 0) { $digits = substr($digits, 2); }
  if (strpos($digits, '233') === 0) { return $digits; }
  if (strlen($digits) === 10 && $digits[0] === '0') { return '233'.substr($digits, 1); }
  return $digits;
}

function sms_send(mysqli $conn, string $to, string $text, ?int $clientId = null, ?string $scheduleFor = null, ?string $tzOffset = null, bool $skipLog = false): array {
  $to = trim($to);
  $text = trim($text);
  if ($to === '' || $text === '') {
    return ['success'=>false, 'status'=>'failed', 'error'=>'Missing phone or message'];
  }
  $provider = 'smsonline';
  $apiKey = _env('SMSONLINE_API_KEY', '');
  $host = _env('SMSONLINE_HOST', 'api.smsonlinegh.com');
  $sender = _env('SMSONLINE_SENDER', 'LMGCM');
  $dialCode = _env('DEFAULT_DIAL_CODE', '233');
  $status = 'queued';
  $providerId = null;
  $error = '';
  $simulate = ($apiKey === '');
  $scheduledAt = null;
  $offsetStr = null;
  if (!$simulate) {
    try {
      if (!class_exists(SMSRequest::class)) {
        throw new \Exception('SMS provider library not available');
      }
      $hosts = array_unique(array_filter([$host, 'smsonlinegh.com', 'api.smsonlinegh.com']));
      $authProfile = null;
      $lastErr = null;
      foreach ($hosts as $h) {
        try {
          $authReq = new AuthRequest();
          $authReq->setHost($h);
          $authReq->useSecureConnection(true, true);
          $authReq->setAuthModel(AuthModel::API_KEY);
          $authReq->setAuthApiKey($apiKey);
          $authProfile = $authReq->authenticate();
          $host = $h;
          break;
        } catch (\Exception $e) {
          $lastErr = $e->getMessage();
          continue;
        }
      }
      if (!$authProfile) {
        throw new \Exception($lastErr ?: 'Authentication request failed');
      }
      $request = new SMSRequest($authProfile);
      $request->setHost($host);
      $request->useSecureConnection(true, true);
      $request->setMessage($text);
      $request->setSMSType(SMSType::GSM_DEFAULT);
      $request->setSender($sender);
      $toFmt = ghana_number_formatter($to);
      $request->addDestination($toFmt);
      $request->validateDestinationSenderName($toFmt);
      $scheduledAt = null;
      $offsetStr = null;
      if (!is_null($scheduleFor) && trim($scheduleFor) !== '') {
        $tmpDt = new \DateTime($scheduleFor);
        $scheduledAt = $tmpDt;
        if (is_null($tzOffset) || trim($tzOffset) === '' || !preg_match('/^[+-]\\d{2}:\\d{2}$/', trim($tzOffset))) {
          $offsetStr = '+00:00';
          $request->setScheduleDateTime($scheduledAt, 'Africa', 'Accra');
        } else {
          $offsetStr = trim($tzOffset);
          $request->setScheduleDateTime($scheduledAt, $offsetStr);
        }
      }
      $msgResp = $request->submit();
      $report = $msgResp ? $msgResp->getReport() : null;
      if ($report) {
        $dests = $report->getDestinations();
        $items = $dests->getItems();
        if (is_array($items) && count($items) > 0) {
          $providerId = $items[0]->getMessageId();
          $statuses = array_map(function($it){ return (int)$it->getStatus(); }, $items);
          $hasDelivered = in_array(DestinationStatus::DS_DELIVERED, $statuses, true);
          $hasSubmitted = in_array(DestinationStatus::DS_SUBMITTED, $statuses, true)
            || in_array(DestinationStatus::DS_SUBMIT_ENROUTE, $statuses, true)
            || in_array(DestinationStatus::DS_SUBMIT_PROCESSING, $statuses, true);
          $hasQueued = in_array(DestinationStatus::DS_SUBMIT_QUEUED, $statuses, true)
            || in_array(DestinationStatus::DS_SUBMIT_PENDING_ACK, $statuses, true)
            || in_array(DestinationStatus::DS_PENDING_DELIV_REPT, $statuses, true);
          $rejectedCodes = [
            DestinationStatus::DS_REJECTED,
            DestinationStatus::DS_REJECTED_DESTINATION,
            DestinationStatus::DS_REJECTED_SENDER,
            DestinationStatus::DS_REJECTED_SENDER_UNREGISTERED,
            DestinationStatus::DS_REJECTED_INSUFF_CREDIT,
            DestinationStatus::DS_REJECTED_NETWORK,
            DestinationStatus::DS_REJECTED_OPERATOR,
            DestinationStatus::DS_REJECTED_OPERATOR_SPAM,
            DestinationStatus::DS_REJECTED_MESSAGE,
            DestinationStatus::DS_REJECTED_MESSAGE_TYPE,
            DestinationStatus::DS_UNDELIVERED,
            DestinationStatus::DS_EXPIRED
          ];
          $hasRejected = false;
          foreach ($statuses as $sid) {
            if (in_array($sid, $rejectedCodes, true)) { $hasRejected = true; break; }
          }
          if ($scheduledAt instanceof \DateTime) {
            $status = 'queued';
          } else if ($hasDelivered || $hasSubmitted) {
            $status = 'sent';
          } else if ($hasQueued) {
            $status = 'queued';
          } else if ($hasRejected) {
            $status = 'failed';
            $error = 'Rejected or undelivered';
          } else {
            $status = 'sent';
          }
        }
      }
    } catch (\Exception $ex) {
      $status = 'failed';
      $error = $ex->getMessage();
    }
  } else {
    $status = 'failed';
    $error = 'SMS provider API key not configured';
  }
  if (!$skipLog && $conn->query("SHOW TABLES LIKE 'sms_messages'")->num_rows > 0) {
    $hasScheduledFor = $conn->query("SHOW COLUMNS FROM sms_messages LIKE 'scheduled_for'")->num_rows > 0;
    $hasOffset = $conn->query("SHOW COLUMNS FROM sms_messages LIKE 'schedule_offset'")->num_rows > 0;
    $provIdStr = $providerId ? (string)$providerId : null;
    $cid = $clientId ? (int)$clientId : null;
    $toLog = isset($toFmt) && $toFmt ? $toFmt : $to;
    if ($hasScheduledFor && $hasOffset) {
      $stmt = $conn->prepare("INSERT INTO sms_messages (client_id, phone, message, status, provider, provider_message_id, error, scheduled_for, schedule_offset) VALUES (?,?,?,?,?,?,?,?,?)");
      $scheduledStr = $scheduledAt ? $scheduledAt->format('Y-m-d H:i:s') : null;
      $stmt->bind_param('issssssss', $cid, $toLog, $text, $status, $provider, $provIdStr, $error, $scheduledStr, $offsetStr);
    } else {
      $stmt = $conn->prepare("INSERT INTO sms_messages (client_id, phone, message, status, provider, provider_message_id, error) VALUES (?,?,?,?,?,?,?)");
      $stmt->bind_param('issssss', $cid, $toLog, $text, $status, $provider, $provIdStr, $error);
    }
    $stmt->execute();
    $stmt->close();
  }
  return ['success'=>($status==='sent' || $status==='queued'), 'status'=>$status, 'provider_message_id'=>$providerId, 'error'=>$error];
}
function sms_connectivity_check(): array {
  $apiKey = _env('SMSONLINE_API_KEY', '');
  $host = _env('SMSONLINE_HOST', 'api.smsonlinegh.com');
  if ($apiKey === '') {
    return ['ok'=>false, 'host'=>$host, 'error'=>'API key missing'];
  }
  $hosts = array_unique(array_filter([$host, 'smsonlinegh.com', 'api.smsonlinegh.com']));
  $lastErr = null;
  foreach ($hosts as $h) {
    try {
      $authReq = new AuthRequest();
      $authReq->setHost($h);
      $authReq->useSecureConnection(true, true);
      $authReq->setAuthModel(AuthModel::API_KEY);
      $authReq->setAuthApiKey($apiKey);
      $ap = $authReq->authenticate();
      if ($ap && $ap->authenticated()) {
        return ['ok'=>true, 'host'=>$h];
      }
      $lastErr = 'Auth profile not authenticated';
    } catch (\Exception $e) {
      $lastErr = $e->getMessage();
    }
  }
  return ['ok'=>false, 'host'=>$host, 'error'=>$lastErr ?: 'Unknown'];
}
?>
