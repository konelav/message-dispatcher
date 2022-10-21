<?php

/********************
 * Global constants *
 ********************/
define("CONFIG_PATH", "config.json");
define("STATE_PATH", "state.json");

define("LOG_PATH", "dispatch.log");
define("LOG_LEVEL", 4);
define("LOG_TO_STDOUT", false);


/********************
 * Standard replies *
 ********************/
$SUBSCRIBE_MSG = <<<SUBMSG

------------------------
Для подписки на рассылку отправьте в ответном сообщении слово "subscribe" или "подписаться"
SUBMSG;
$UNSUBSCRIBE_MSG = <<<UNSUBMSG

------------------------
Для отписки от рассылки отправьте в ответном сообщении слово "unsubscribe" или "отписаться"
UNSUBMSG;


/*************
 * Libraries *
 *************/
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
@include('libphp-phpmailer/autoload.php');


/*************************
 * Logging/path routines *
 *************************/
function local_dispatcher_path( $path ) {
    static $root = null;
    if ( is_null( $root ) )
        $root = dirname( __FILE__ );
    if ( strlen( $path ) == 0 )
        return $root;
    if ( $path[0] == '/' )
        return $path;
    return $root . '/' . $path;
}

function tolog( $file, $nline, $msg, $level = 0) {
    static $flog = null;
    if ( $level > LOG_LEVEL )
        return;
    $line  = '[' . strftime('%Y-%m-%d %H:%M:%S') . '] ';
    $line .= '[' . basename($file) .':' . $nline . '] ';
    $line .= $msg ;
    $line .= "\n";
    if ( LOG_TO_STDOUT )
        echo ( $line );
    try {
        if ($flog == null)
            $flog = fopen( local_dispatcher_path(LOG_PATH), 'a+' );
        fwrite($flog, $line);
        fflush($flog);
    }
    catch (Exception $ex) {
    }
}

function log_error( $file, $nline, $msg ) {
    tolog( $file, $nline,  '(ERROR) ' . $msg, 1);
}
function log_warning( $file, $nline, $msg ) {
    tolog( $file, $nline,  '(warning) ' . $msg, 2);
}
function log_info( $file, $nline, $msg ) {
    tolog( $file, $nline,  '(info) ' . $msg, 3);
}
function log_debug( $file, $nline, $msg ) {
    tolog( $file, $nline,  '(debug) ' . $msg, 4);
}
function log_lldebug( $file, $nline, $msg ) {
    tolog( $file, $nline,  '(lldebug) ' . $msg, 5);
}


/*******************************************
 * Reading/writing configuration and state *
 *******************************************/
function is_list( $x ) {
    if ( ! is_array( $x ) )
        return false;
    return count(array_filter(array_keys($x), 'is_string')) == 0;
}

function remove_values( & $array, $values ) {
    $changed = false;
    if ( ! is_array( $values ) )
        $values = [ $values ];
    foreach ( $values as $to_remove )
        while ( ($remove_key = array_search( $to_remove, $array )) !== false ) {
            unset ( $array[ $remove_key ] );
            $changed = true;
        }
    if ( $changed )
        $array = array_values( $array );
    return $changed;
}

function insert_values( & $array, $values ) {
    $changed = false;
    if ( ! is_array( $values ) )
        $values = [ $values ];
    foreach ( $values as $to_add )
        if ( ($old_key = array_search( $to_add, $array )) === false ) {
            $array[] = $to_add;
            $changed = true;
        }
    return $changed;
}

function update_array( & $array, $changes ) {
    $changed = false;
    
    foreach ( $changes as $change ) {
        $set = $change[ 'set' ] ?? [];
        $unset = $change[ 'unset' ] ?? [];
        
        foreach ( $unset as $key => $unset_val ) {
            if ( ! array_key_exists($key, $array) ) {
            }
            elseif ( is_list( $array[ $key ] ) ) {
                if ( remove_values( $array[ $key ], $unset_val ) )
                    $changed = true;
            }
            elseif ( is_array( $array[ $key ] ) && is_array( $unset_val ) ) {
                if ( update_array( $array[ $key ], [ ['unset' => $unset_val] ] ) )
                    $changed = true;
            }
            else {
                unset ( $array[ $key ] );
                $changed = true;
            }
        }
        
        foreach ( $set as $key => $set_val ) {
            if ( ! array_key_exists($key, $array) ) {
                $array[ $key ] = $set_val;
                $changed = true;
            }
            elseif ( is_list( $array[ $key ] ) ) {
                if ( insert_values( $array[ $key ], $set_val ) )
                    $changed = true;
            }
            elseif ( is_array( $array[ $key ] ) && is_array( $set_val ) ) {
                if ( update_array( $array[ $key ], [ ['set' => $set_val] ] ) )
                    $changed = true;
            }
            elseif ( $array[ $key ] != $set_val ) {
                $array[ $key ] = $set_val;
                $changed = true;
            }
        }
    }
    
    return $changed;
}

function read_json($path) {
    $ret = [];
    $path = local_dispatcher_path( $path );
    try {
        clearstatcache();
        if ( filesize( $path ) == 0 ) {
            log_debug( __FILE__, __LINE__, 'Creating empty JSON file ' . $path );
            file_put_contents( $path, '{}' );
            return $ret;
        }
        $file = fopen($path, 'r');
        if ( flock( $file, LOCK_SH ) ) {
            $contents = fread( $file, filesize( $path ) );
            log_lldebug( __FILE__, __LINE__, 'Read JSON file ' . $path . ': ' . strlen($contents) . ' byte(s)' );
            $ret = json_decode( $contents, true );
        }
        else
            log_warning( __FILE__, __LINE__, 'Can\'t lock file ' . $path );
        fclose( $file );
    }
    catch (Exception $ex) {
        log_error(__FILE__, __LINE__, $ex->getMessage());
    }
    return $ret;
}

function update_json($path, $changes, $flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) {
    $path = local_dispatcher_path( $path );
    $data = read_json( $path );
    if ( ! update_array( $data, $changes ) ) {
        log_lldebug( __FILE__, __LINE__, 'Data of JSON file ' . $path . ' unchanged' );
        return true;
    }
    try {
        ksort($data);
        $contents = json_encode($data, $flags);
        $file = fopen($path, 'w');
        $ret = false;
        if ( flock( $file, LOCK_EX ) ) {
            fwrite($file, $contents);
            log_debug( __FILE__, __LINE__, 'Written JSON file ' . $path . ': ' . strlen($contents) . ' byte(s)' );
            $ret = true;
        }
        else
            log_warning( __FILE__, __LINE__, 'Can\'t lock file ' . $path );
        fclose($file);
        return $ret;
    }
    catch (Exception $ex) {
        log_error(__FILE__, __LINE__, $ex->getMessage());
        return false;
    }
}


/**************************************************
 * Creating zip-archives (needed for attachments) *
 **************************************************/
function zip_dir( $dirpath, $zippath ) {
    log_debug( __FILE__, __LINE__, 'Archiving directory ' . $dirpath . ' to ' . $zippath );
    $info = pathInfo( $dirpath );
    $parent = $info[ 'dirname' ];
    $name = $info[ 'basename' ];
    $z = new ZipArchive();
    $z->open( $zippath, ZIPARCHIVE::CREATE );
    $z->addEmptyDir( $name );
    $parlen = strlen( $parent );
    $handle = opendir( $dirpath );
    if ( $handle === false ) {
        log_error( __FILE__, __LINE__, 'Can\t open directory for archiving: ' . $dirpath );
        return;
    }
    while ( $handle !== false && ( $f = readdir($handle) ) !== false ) {
        if ( $f != '.' && $f != '..' ) {
            $fpath = "$dirpath/$f";
            $locpath = substr($fpath, $parlen);
            if ( is_file( $fpath ) )
                $z->addFile( $fpath, $locpath );
        }
    }
    closedir( $handle );
    $z->close();
}


/*******************************
 * Helpers for e-mail handling *
 *******************************/
function extract_emails($s) {
    $pattern = '/[a-z0-9_\-\+\.]+@[a-z0-9\-]+\.([a-z]{2,4})(?:\.[a-z]{2})?/i';
    preg_match_all( $pattern, $s, $matches );
    return array_map(
        function ( $x ) {
            return strtolower( $x[0] );
        },
        $matches
    );
}

function mime_encode($string, $encoding = 'UTF-8') {
    $pos = 0;
    $split = 24;
    while ( $pos < mb_strlen( $string, $encoding ) ) {
        $output = mb_strimwidth( $string, $pos, $split, '', $encoding );
        $pos += mb_strlen( $output, $encoding );
        $_string_encoded = '=?' . $encoding . '?B?' . base64_encode( $output ) . '?=';
        if ( $_string )
            $_string .= "\r\n ";
        $_string .= $_string_encoded;
    }
    $string = $_string;
    return $string;
}


/***************************
 * IMAP interface wrappers *
 ***************************/
function decode_data($data, $encoding) {
    if ( $encoding == 3 ) {
        $data = base64_decode( $data );
    } elseif ( $encoding == 4 ) {
        $data = quoted_printable_decode( $data );
    }
    return $data;
}
function fetch_and_decode($mbox, $msguid, $part, $npart) {
    log_debug( __FILE__, __LINE__, 'Fetching msg ' . $msguid . ', part ' . $npart );
    $data = imap_fetchbody( $mbox, $msguid, $npart, FT_UID );
    return decode_data( $data, $part->encoding );
}

function fetch_mail($mbox, $msguid, $ignore_attachments = false) {
    log_debug( __FILE__, __LINE__, 'Fetching mail msg ' . $msguid );
    
    $overview = imap_fetch_overview($mbox, $msguid, FT_UID)[0];
    $subject = imap_utf8($overview->subject ?? '');
    $structure = imap_fetchstructure($mbox, $msguid, FT_UID);
    
    $attachments = [];
    $contents = [];
    
    if ( ! property_exists($structure, 'parts') ) {
        log_debug( __FILE__, __LINE__, 'No parts, just plaintext or html' );
        $body = decode_data( imap_body($mbox, $msguid, FT_UID), $structure->encoding );
        $contents[ 'PLAIN' ] = strip_tags( imap_utf8($body) );
    }
    else {
        log_debug( __FILE__, __LINE__, 'Found ' . count($structure->parts) . ' part(s)' );
        
        $subtype = strtolower($structure->subtype);
        $parameters = $structure->parameters;
        $value = $parameters[0]->value;
        
        foreach ($structure->parts as $npart => $part) {
            $is_attachment = (isset($part->disposition) && $part->disposition == 'ATTACHMENT');
            if ( $ignore_attachments && $is_attachment )
                continue;
            $filename = $name = null;
            if ($part->ifdparameters) {
                foreach ($part->dparameters as $object) {
                    if (strtolower($object->attribute) == 'filename') {
                        $is_attachment = true;
                        $filename = imap_utf8($object->value);
                        break;
                    }
                }
            }
            if ($part->ifparameters) {
                foreach ($part->parameters as $object) {
                    if (strtolower($object->attribute) == 'name') {
                        $is_attachment = true;
                        $name = imap_utf8($object->value);
                        break;
                    }
                }
            }
            
            if ( $is_attachment ) {
                log_debug( __FILE__, __LINE__, 'Part #' . $npart . ' is attachment [' . $filename . '; ' . $name . ']' );
                $attachments[] = [
                    'filename' => $filename ?? '',
                    'name' => $name ?? '',
                    'attachment' => fetch_and_decode($mbox, $msguid, $part, $npart + 1) ?? ''
                ];
            }
            elseif ( property_exists($part, 'parts') ) {
                log_debug( __FILE__, __LINE__, 'Part #' . $npart . ' has ' . count($part->parts) . ' subpart(s)' );
                foreach ($part->parts as $nsubpart => $subpart) {
                    $subpartid = strval($npart + 1) . '.' . strval($nsubpart + 1);
                    $contents[$subpart->subtype] = fetch_and_decode($mbox, $msguid, $subpart, $subpartid);
                }
            }
            else {
                log_debug( __FILE__, __LINE__, 'Part #' . $npart . ' is plaintext or html' );
                $contents[ 'PLAIN' ] = strip_tags( fetch_and_decode($mbox, $msguid, $part, $npart + 1) );
            }
        }
    }

    return [ 
        'subject' => $subject,
        'contents' => $contents,
        'attachments' => $attachments
    ];
}

function find_special_lines($contents, $specials) {
    $body = strip_tags( $contents[ 'PLAIN' ] ?? $contents[ 'HTML' ] ?? '' );
    $body = ' ' . strtr( $body, 
        "\r\n\t.,:;!?-_<>[]{}()'\"$&^*+=\\|/`@#%", 
        '                                       ' 
    ) . ' ';
    $body = mb_strtolower( $body );
    $ret = [];
    foreach ( $specials as $name => $words ) {
        $pattern = '/\s+((' . implode('|', $words) . ')\S*)/i';
        preg_match_all($pattern, $body, $matches);
        $ret[ $name ] = count($matches[0]);
        log_debug( __FILE__, __LINE__, 'Mail contains <' . $name . '> [pattern "' . $pattern . '" ]: ' . count($matches[0]) );
    }
    return $ret;
}

function process_incoming_mail($mbox, $msguid, $sources) {
    $header = imap_rfc822_parse_headers( imap_fetchheader( $mbox, $msguid, FT_UID ) );
    $from = extract_emails( imap_utf8( $header->fromaddress ) )[0];
    $is_dispatch_source = in_array( $from, $sources );
    
    log_debug( __FILE__, __LINE__, 'Processing message from [source: ' . ($is_dispatch_source ? 'yes' : 'no') .'] ' . $from );
    
    $mail = fetch_mail( $mbox, $msguid, ! $is_dispatch_source );
    
    if ( $is_dispatch_source ) {
        $mail[ 'from' ] = $from;
        return ['dispatch' => $mail];
    }
    
    $spec_lines = find_special_lines( $mail[ 'contents' ], [
        'subs' =>  ['sub', 'подп'],
        'unsubs' => ['unsub', 'отпи']
    ]);
    
    if ( $spec_lines[ 'subs' ] > 0 )
        return ['sub' => ['address' => $from, 'sub' => true]];
    if ( $spec_lines[ 'unsubs' ] > 0 )
        return ['sub' => ['address' => $from, 'sub' => false]];
    return [];
}

function process_incoming_mails($mbox, $sources) {
    $msgs = imap_sort($mbox, SORTDATE, false, SE_UID);
    log_debug( __FILE__, __LINE__, 'Total messages: ' . count($msgs) );
    if ( count($msgs) == 0 )
        return [];
    
    log_info( __FILE__, __LINE__, 'Mailbox has new messages: ' . count($msgs) );
    
    $ret = [];
    foreach ($msgs as $msguid) {
        $res = process_incoming_mail($mbox, $msguid, $sources);
        
        foreach ( $res as $type => $val )
            $ret[ $type ][] = $val;
        
        if ( count( $res ) == 0 ) {
            log_debug(__FILE__, __LINE__, "imap_mail_move($mbox, strval($msguid), 'Junk', CP_UID);");
            imap_mail_move($mbox, strval($msguid), 'Junk', CP_UID);
        }
        else {
            log_debug(__FILE__, __LINE__, "imap_mail_move($mbox, strval($msguid), 'Trash', CP_UID);");
            imap_mail_move($mbox, strval($msguid), 'Trash', CP_UID);
        }
    }
    imap_expunge($mbox);
    
    return $ret;
}

function store_attachments( $attachments, $basedir, $url_prefix ) {
    if ( count( $attachments ) == 0 )
        return [];
    log_debug( __FILE__, __LINE__, 'Storing ' . count( $attachments ) . ' attachment(s) to ' . $basedir );
    $id = 'attachment-' . bin2hex(openssl_random_pseudo_bytes(8));
    $folder = $basedir . '/' . $id;
    mkdir( local_dispatcher_path( $folder ), 0777, true );
    $files = [];
    foreach ($attachments as $attachment) {
        $filename = ( !empty ( $attachment['name']     )) ? $attachment['name']     :
                    ( !empty ( $attachment['filename'] )) ? $attachment['filename'] :
                    ( time() . '.dat' );
        if ( $filename[0] == '.' )
            $filename = 'renamed-' . $filename;
        $relpath = $folder . '/' . $filename;
        $abspath = local_dispatcher_path( $relpath );
        
        log_info( __FILE__, __LINE__, 'Saving attachment to ' . $abspath);
        
        file_put_contents( $abspath, $attachment['attachment'] );
        $files[] = [
            'path' => $abspath,
            'size' => filesize($abspath),
            'url' => $url_prefix . '/' . $relpath
        ];
    }
    $zip_relpath = $folder . '.zip';
    $zip_abspath = local_dispatcher_path( $zip_relpath );
    zip_dir( local_dispatcher_path( $folder ), $zip_abspath );
    $files[] = [
        'archive' => true,
        'path' => $zip_abspath,
        'size' => filesize($zip_abspath),
        'url' => $url_prefix . '/' . $zip_relpath
    ];
    return $files;
}

function ftp_goto_dir($ftp, $path) {
    ftp_chdir( $ftp, '/' );
    $parts = explode('/', $path);
    foreach ( $parts as $part ) {
        if ( ! empty ( $part ) ) {
            if( ! @ftp_chdir( $ftp, $part ) ) {
                ftp_mkdir( $ftp, $part );
                ftp_chdir( $ftp, $part );
            }
        }
    }
}

function upload_attachments( $files, $ftp, $basedir ) {
    log_info( __FILE__, __LINE__, 'Uploading ' . count( $files ) . ' file(s) to ' . $basedir );
    foreach ( $files as $file ) {
        $filedir = ( 
            ($file[ 'archive' ] ?? false) ? '' : 
            basename( dirname( $file[ 'path' ] ) )
        );
        $dir = $basedir . '/' . $filedir;
        log_debug(__FILE__, __LINE__, 'FTP put ' . $file[ 'path' ] . ' to ' . $dir);
        ftp_goto_dir( $ftp, $dir );
        ftp_put( $ftp, basename( $file[ 'path' ] ), $file[ 'path' ] );
    }
}

function send_mail_imap($mbox, $from, $to, $subject, $text, $files = [] ) {
    $envelope = [
        'from' => $from,
        'date' => date('r'),
        'reply_to' => $from
    ];
    
    $bodies = [];
    $body_part = 1;
    
    if ( count($files) > 0 ) {
        $bodies[$body_part++] = [
            'type' => TYPEMULTIPART,
            'subtype' => 'mixed'
        ];
        foreach ($files as $file) {
            if ( $file[ 'archive' ] ?? false )
                continue;
            $filename = basename( $file[ 'path' ] );
            $path = local_dispatcher_path( $file[ 'path' ] );
            $bodies[$body_part++] = [
                'type' => TYPEAPPLICATION,
                'subtype' => 'octet-stream',
                'encoding' => ENCBASE64,
                'description' => $filename,
                'disposition.type' => 'attachment',
                'disposition' => ['filename' => $filename],
                'dparameters.filename' => $filename,
                'parameters.name' => $filename,
                'type.parameters' => ['name' => $filename],
                'contents.data' => base64_encode(file_get_contents( $path ))
            ];
        }
    }
    
    $bodies[$body_part++] = [
        'type' => TYPETEXT,
        'subtype' => 'plain',
        'charset' => 'UTF-8',
        'encoding' => ENCBASE64,
        'contents.data' => base64_encode($text)
    ];
    
    $msg = imap_mail_compose( $envelope, $bodies );
    $sent = imap_mail($to, mime_encode($subject), ' ', $msg);
    
    log_debug( __FILE__, __LINE__, 'Mail (imap) sent from <' . $from . '> to <' . $to . '> (' . count( $files ) . ' file(s)): ' . $sent );
    
    return $sent;
}

function create_phpmailer($config, $default_port = 25) {
    $mailer = null;
    $smtp = $config[ 'smtp' ] ?? [];
    $host = $smtp[ 'host' ] ?? null;
    if ( ! is_null( $host ) ) {
        if ( ! class_exists('PHPMailer\PHPMailer\PHPMailer') ) {
            log_error( __FILE__, __LINE__, 'Class PHPMailer not found');
            return $mailer;
        }
        $mailer = new PHPMailer(true);
        $mailer->isSMTP();
        $mailer->Host = $host;
        $mailer->Port = $smtp[ 'port' ] ?? $default_port;
        if ( in_array( 'ssl', $smtp ) ) {
            $mailer->SMTPSecure =  PHPMailer::ENCRYPTION_SMTPS;
            $mail->SMTPOptions = [
                'ssl' => $smtp[ 'ssl' ]
            ];
        }
        elseif ( in_array( 'tls', $smtp ) ) {
            $mailer->SMTPSecure =  PHPMailer::ENCRYPTION_STARTTLS;
            $mail->SMTPOptions = [
                'ssl' => $smtp[ 'tls' ]
            ];
        }
        $mailer->SMTPAuth = true;
        $mailer->SMTPKeepAlive = true;
        $mailer->Port = intval( $parts[1] ?? $default_port );
        $mailer->Username = $smtp[ 'username' ] ?? $config[ 'email' ];
        $mailer->Password = $smtp[ 'password' ] ?? $config[ 'password' ];
        $mailer->CharSet = 'UTF-8';
        $mailer->Encoding = 'base64';
    }
    return $mailer;
}

function send_mail_phpmailer($mailer, $from, $to, $subject, $text, $files = [] ) {
    $mailer->setFrom($from);
    $mailer->addReplyTo($from);
    
    $mailer->Subject = $subject;
    $mailer->Body = $text;
    
    foreach ($files as $file) {
        if ( $file[ 'archive' ] ?? false )
            continue;
        $path = local_dispatcher_path( $file[ 'path' ] );
        $mailer->addStringAttachment( file_get_contents( $path ), basename( $path ) );
    }
    
    $mailer->addAddress($to);
    
    $sent = false;
    try {
        $sent = $mailer->send();
        log_debug( __FILE__, __LINE__, 'Mail (phpmailer) sent from <' . $from . '> to <' . $to . '> with ' . count( $files ) . ' file(s)' );
    } catch (Exception $ex) {
        log_error( __FILE__, __LINE__, $ex->getMessage() );
        $mailer->getSMTPInstance()->reset();
    }
    
    $mailer->clearAddresses();
    $mailer->clearAttachments();
    
    return $sent;
}

function send_mail_generic($mbox, $mailer, $from, $to, $subject, $text, $files = []) {
    $ret = false;
    if ( ! is_null( $mailer ) )
        $ret = send_mail_phpmailer( $mailer, $from, $to, $subject, $text, $files );
    if ( ! $ret )
        $ret = send_mail_imap( $mbox, $from, $to, $subject, $text, $files );
    return $ret;
}


/********************
 * API call helpers *
 ********************/
function http_json_call( $url, $data, $headers = [], $loglevel = 4 ) {
    if ( is_null( $data ) || count( $data ) == 0 )
        $content = '{}';
    else
        $content = json_encode( $data, JSON_UNESCAPED_UNICODE );
    tolog( __FILE__, __LINE__, 'http_json_call(' . $url . ', ' . $content . ')', $loglevel );
    $header = "Content-type: application/json\r\n";
    foreach ($headers as $key => $value)
        $header .= $key . ': ' . $value . "\r\n";
    $options = array(
        'http' => array(
            'header'  => $header,
            'method'  => 'POST',
            'content' => $content
        )
    );
    try {
        $context  = stream_context_create($options);
        $result = file_get_contents($url, false, $context);
    }
    catch (Exception $ex) {
        log_error(__FILE__, __LINE__, $ex->getMessage());
        return [];
    }
    tolog( __FILE__, __LINE__, ' => ' . $result, $loglevel );
    return json_decode($result, true);
}


/*********************************************************
 * Telegram bot API [https://core.telegram.org/bots/api] *
 *********************************************************/
function tg_call( $token, $method, $data ) {
    if ( is_null($token) )
        return [];
    return http_json_call(
        'https://api.telegram.org/bot' . $token . '/' . $method,
        $data,
        []
    );
}

function tg_broadcast( $token, $method, $chat_ids, $data ) {
    if ( is_null($token) || count($chat_ids) == 0)
        return true;
    $ret = false;
    foreach ($chat_ids as $chat_id) {
        $data[ 'chat_id' ] = $chat_id;
        $res = tg_call( $token, $method, $data );
        if ( $res[ 'ok' ] ?? false )
            $ret = true;
    }
    return $ret;
}

function tg_broadcast_file( $token, $file, $chat_ids ) {
    $name = basename( $file[ 'path' ] );
    if (preg_match('/\.(jpg|jpeg|gif|png|bmp|tif|tiff)$/', $name))
        return tg_broadcast( $token, 'sendPhoto', $chat_ids, ['photo' => $file['url']] );
    if (preg_match('/\.(mp4|mpg|mpeg|avi|webm)$/', $name))
        return tg_broadcast( $token, 'sendVideo', $chat_ids, ['video' => $file['url']] );
    if (preg_match('/\.(mp3|wav|mid)$/', $name))
        return tg_broadcast( $token, 'sendAudio', $chat_ids, ['audio' => $file['url']] );
    if (preg_match('/\.(zip|rar|7z)$/', $name))
        return tg_broadcast( $token, 'sendDocument', $chat_ids, ['document' => $file['url']] );
    return false;
}


/***********************************************************************
 * Viber bot API [https://developers.viber.com/docs/api/rest-bot-api/] *
 ***********************************************************************/
function viber_bot_call( $token, $method, $data, $loglevel = 3 ) {
    if ( is_null($token) )
        return [];
    return http_json_call(
        'https://chatapi.viber.com/pa/' . $method,
        $data,
        ['X-Viber-Auth-Token' => $token],
        $loglevel
    );
}

function viber_bot_broadcast( $token, $ids, $data ) {
    if ( is_null($token) || count($ids) == 0)
        return true;
    $data[ 'broadcast_list' ] = $ids;
    $res = viber_bot_call($token, 'broadcast_message', $data);
    return ($res[ 'status' ] ?? -1) === 0;
}

function viber_bot_broadcast_file( $token, $file, $sender, $ids ) {
    $name = basename( $file[ 'path' ] );
    if (preg_match('/\.(jpg|jpeg|gif|png|bmp|tif|tiff)$/', $name))
        return viber_bot_broadcast( $token, $ids, [
                'type' => 'picture', 'media' => $file['url'], 'sender' => ['name' => $sender]
            ]);
    if (preg_match('/\.(mp4|mpg|mpeg|avi|webm)$/', $name))
        return viber_bot_broadcast( $token, $ids, [
                'type' => 'video', 'media' => $file['url'], 'size' => $file['size'], 'sender' => ['name' => $sender]
            ]);
    if (preg_match('/\.(zip|rar|7z)$/', $name))
        return viber_bot_broadcast( $token, $ids, [
                'type' => 'file', 'media' => $file['url'], 'file_name' => $name, 'size' => $file['size'], 'sender' => ['name' => $sender]
            ]);
    return false;
}

/***************************************************************************************
 * Viber channel post API [https://developers.viber.com/docs/tools/channels-post-api/] *
 ***************************************************************************************/
function viber_chat_call( $token, $method, $data, $loglevel = 3 ) {
    if ( is_null($token) )
        return [];
    $data[ 'auth_token' ] = $token;
    return http_json_call(
        'https://chatapi.viber.com/pa/' . $method,
        $data,
        [],
        $loglevel
    );
}

function viber_chat_find_superadmin( $token, $config ) {
    if ( is_null($token) )
        return null;
    if ( array_key_exists( 'viber-chat-admin', $config ) )
        return $config[ 'viber-chat-admin' ];
    
    $res = viber_chat_call( $token, 'get_account_info', [] );
    if ( ($res[ 'status' ] ?? -1) !== 0 ) {
        viber_chat_call( $token, 'set_webhook', ['url' => ($config['url-prefix'] . '/viber_webhook.php')] );
        $res = viber_chat_call( $token, 'get_account_info', [] );
    }
    $members = $res[ 'members' ] ?? [];
    $id = null;
    foreach ( $members as $member ) {
        if ( $member['role'] == 'superadmin' ) {
            $id = $member['id'];
            log_info( __FILE__, __LINE__, 'Viber superadmin found: "' . $id . '"' );
        }
    }
    return $id;
}

function viber_chat_file( $token, $file, $sender ) {
    $name = basename( $file[ 'path' ] );
    if (preg_match('/\.(jpg|jpeg|gif|png|bmp|tif|tiff)$/', $name))
        return viber_chat_call( $token, 'post', [
                'type' => 'picture', 'media' => $file['url'], 'from' => $sender
            ]);
    if (preg_match('/\.(mp4|mpg|mpeg|avi|webm)$/', $name))
        return viber_chat_call( $token, 'post', [
                'type' => 'video', 'media' => $file['url'], 'size' => $file['size'], 'from' => $sender
            ]);
    if (preg_match('/\.(zip|rar|7z)$/', $name))
        return viber_chat_call( $token, 'post', [
                'type' => 'file', 'media' => $file['url'], 'file_name' => $name, 'size' => $file['size'], 'from' => $sender
            ]);
    return false;
}


/************************
 * Dispatching routines *
 ************************/
function dispatch_to_viber_bot( $sender, $config, $state, $subject, $text, $files ) {
    $token = $config[ 'viber-bot' ] ?? null;
    if ( is_null( $token ) )
        return;
    $ids = array_values( $state['viber-bot-subs'] ?? [] );
    viber_bot_broadcast( $token, $ids, [
        'type' => 'text',
        'sender' => ['name' => $sender],
        'text' => $subject . ' ' . $text
    ]);
    $files_sent = $total_files = 0;
    $archive = null;
    foreach ($files as $file) {
        if ( $file[ 'archive' ] ?? false )
            $archive = $file;
        else {
            $total_files++;
            if (viber_bot_broadcast_file( $token, $file, $sender, $ids ))
                $files_sent++;
        }
    }
    if ( $total_files > 0 )
        log_info( __FILE__, __LINE__, 'viber-bot sent ' . $files_sent . ' file(s) out of ' . $total_files );
    if ( $total_files != $files_sent && ! is_null( $archive ) )
        viber_bot_broadcast_file( $token, $archive, $sender, $ids );
}

function dispatch_to_viber_chat( $config, $subject, $text, $files ) {
    $token = $config[ 'viber-chat' ] ?? null;
    if ( is_null( $token ) )
        return;
    $viber_from = viber_chat_find_superadmin($token, $config);
    viber_chat_call( $token, 'post', [
        'type' => 'text',
        'from' => $viber_from,
        'text' => $subject . ' ' . $text
    ]);
    $files_sent = $total_files = 0;
    $archive = null;
    foreach ($files as $file) {
        if ( $file[ 'archive' ] ?? false )
            $archive = $file;
        else {
            $total_files++;
            if (viber_chat_file( $token, $file, $viber_from ))
                $files_sent++;
        }
    }
    if ( $total_files > 0 )
        log_info( __FILE__, __LINE__, 'viber-chat sent ' . $files_sent . ' file(s) out of ' . $total_files );
    if ( $total_files != $files_sent && ! is_null( $archive ) )
        viber_chat_file( $token, $archive, $viber_from );
}

function dispatch_to_tg( $config, $state, $subject, $text, $files ) {
    $token = $config[ 'tg-bot' ] ?? null;
    if ( is_null( $token ) )
        return;
    $ids = array_values( $state['tg-bot-subs'] ?? [] );
    tg_broadcast( $token, 'sendMessage', $ids, ['text' => $subject . ' ' . $text] );
    $files_sent = $total_files = 0;
    $archive = null;
    foreach ($files as $file) {
        if ( $file[ 'archive' ] ?? false )
            $archive = $file;
        else {
            $total_files++;
            if (tg_broadcast_file( $token, $file, $ids ))
                $files_sent++;
        }
    }
    if ( $total_files > 0 )
        log_info( __FILE__, __LINE__, 'telegram sent ' . $files_sent . ' file(s) out of ' . $total_files );
    if ( $total_files != $files_sent && ! is_null( $archive ) )
        tg_broadcast_file( $token, $archive, $ids );
}

function dispatch_to_mail( $mbox, $mailer, $config, $state, $subject, $text, $files ) {
    $subs = $state[ 'mail-subs' ] ?? [];
    log_debug( __FILE__, __LINE__, 'Dispatching to ' . count( $subs ) . ' email(s)' );
    foreach ( $subs as $sub ) {
        send_mail_generic( $mbox, $mailer, $config[ 'email' ], $sub, $subject, $text, $files );
    }
}

function dispatch_message( $dispatcher, $config, $state, $subject, $text, $files, $mbox, $mailer ) {
    global $UNSUBSCRIBE_MSG;
    
    log_info( __FILE__, __LINE__, '[' . $dispatcher . ']Dispatching message <' . $subject . '> with ' . count( $files ) . ' file(s)' );
    dispatch_to_viber_bot( $dispatcher, $config, $state, $subject, $text, $files );
    dispatch_to_viber_chat( $config, $subject, $text, $files );
    dispatch_to_tg( $config, $state, $subject, $text, $files );
    
    $text .= $UNSUBSCRIBE_MSG;
    dispatch_to_mail( $mbox, $mailer, $config, $state, $subject, $text, $files );
}


/**********************
 * Top-level routines *
 **********************/
function viber_handle_webhook( $headers, $body ) {
    log_info( __FILE__, __LINE__, 'Viber webhook called, body = ' . $body );
    try {
        $sign = $headers[ 'X-Viber-Content-Signature' ];
        $message = json_decode($body, true);
    }
    catch (Exception $ex) {
        log_error(__FILE__, __LINE__, $ex->getMessage());
        return false;
    }
    
    $configs = read_json( CONFIG_PATH );
    $states = read_json( STATE_PATH );
    $changes = [];

    foreach ( $configs as $dispatcher => $config ) {
        $token = $config[ 'viber-bot' ] ?? null;
        if ( is_null( $token ) || ($config[ 'disabled' ] ?? false))
            continue;
        $hmac = hash_hmac('sha256', $body, $token );
        if ( $hmac != $sign )
            continue;
        log_debug( __FILE__, __LINE__, 'Correct signature for dispatcher ' . $dispatcher);
        
        $subs = $states[ $dispatcher ][ 'viber-bot-subs' ];
    
        if ( $message[ 'event' ] == 'subscribed' ) {
            $uid = $message[ 'user' ][ 'id' ];
            if ( ! in_array( $uid, $subs ) ) {
                log_info( __FILE__, __LINE__, 'Add new subscriber for ' . $dispatcher . ': ' . $uid);
                $changes[] = ['set' => [$dispatcher => ['viber-bot-subs' => [ $uid ]]]];
            }
        }
        elseif ( $message[ 'event' ] == 'unsubscribed' ) {
            $uid = $message[ 'user_id' ];
            if ( ($key = array_search($uid, $subs)) !== false ) {
                log_info( __FILE__, __LINE__, 'Remove subscriber for ' . $dispatcher . ': ' . $uid);
                $changes[] = ['unset' => [$dispatcher => ['viber-bot-subs' => [ $uid ]]]];
            }
        }
        elseif ( ( $message[ 'event' ] == 'conversation_started' ) &&
                  !is_null( $welcome = $config[ 'viber_bot_welcome_message' ] ?? null ) ) {
            $uid = $message[ 'user_id' ];
            if ( ! in_array( $uid, $subs ) ) {
                log_info( __FILE__, __LINE__, 'Add new subscriber for ' . $dispatcher . ': ' . $uid);
                $response = [
                    'type' => 'text',
                    'sender' => ['name' => $dispatcher],
                    'text' => $welcome
                ];
                echo ( json_encode( $response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) );
            }
        }
    }
    return update_json( STATE_PATH, $changes );
}

function viber_check_webhooks( $always_set = false, $silent = true ) {
    $configs = read_json( CONFIG_PATH );
    $states = read_json( STATE_PATH );
    $changes = [];
    
    $msg = '';
    
    foreach ( $configs as $dispatcher => $config ) {
        $msg .= 'Checking Viber setup for <' . $dispatcher . '>' . "\n";
        
        if ( $config[ 'disabled' ] ?? false ) {
            $msg .= '    dispatcher is disabled' . "\n";
            continue;
        }
        
        if ( is_null( $token = $config[ 'viber-bot' ] ?? null ) )
            $msg .= '    bot token not set' . "\n";
        elseif ( $always_set || !($states[ $dispatcher ][ 'viber-bot-webhooked' ] ?? false) ) {
            $res = viber_bot_call( $token, 'set_webhook', ['url' => ($config['url-prefix'] . '/viber_webhook.php')] );
            $code = $res[ 'status' ] ?? -1;
            $changes[] = ['set' => [$dispatcher => ['viber-bot-webhooked' => ($code === 0)]]];
            $msg .= '    bot API webhook result: ' . $code . "\n";
        }
        else
            $msg .= '    bot API webhook already set' . "\n";
        
        if ( is_null( $token = $config[ 'viber-chat' ] ?? null ) )
            $msg .= '    chat post token not set' . "\n";
        elseif ( $always_set || !($states[ $dispatcher ][ 'viber-chat-webhooked' ] ?? false) ) {
            $res = viber_chat_call( $token, 'set_webhook', ['url' => ($config['url-prefix'] . '/viber_webhook.php')] );
            $code = $res[ 'status' ] ?? -1;
            $changes[] = ['set' => [$dispatcher => ['viber-chat-webhooked' => ($code === 0)]]];
            $msg .= '    chat post API webhook result: ' . $code . "\n";
        }
        else
            $msg .= '    chat post API webhook already set' . "\n";
    }
    
    if ( ! $silent )
        echo ( $msg );
    
    return update_json( STATE_PATH, $changes );
}

function tg_check_updates() {
    $configs = read_json( CONFIG_PATH );
    $states = read_json( STATE_PATH );
    $changes = [];
    
    foreach ( $configs as $dispatcher => $config ) {
        $token = $config[ 'tg-bot' ] ?? null;
        if ( is_null( $token ) || ($config[ 'disabled' ] ?? false))
            continue;
        
        $last_update_id = $states[ $dispatcher ][ 'tg-last-update-id' ] ?? -1;
        
        $ret = tg_call($token, 'getUpdates', [
            'offset' => $last_update_id + 1,
            'allowed_updates' => ( $config['tg_allowed_updates'] ?? [] )
        ]);

        if ( count( $ret[ 'result' ] ?? [] ) == 0 )
            continue;
        
        log_info(__FILE__, __LINE__, 'New telegram updates for disaptcher ' . $dispatcher . ': ' . 
            count($ret[ 'result' ]) . ";\n" .  '    ' . json_encode( $ret, JSON_UNESCAPED_UNICODE ) );
        
        foreach ( $ret[ 'result' ] as $update ) {
            $changes[] = ['set' => [$dispatcher => ['tg-last-update-id' => $update[ 'update_id' ]]]];
            
            if ( array_key_exists( 'my_chat_member', $update ) ) {
                $member = $update[ 'my_chat_member' ];
                $chat_id = $member[ 'chat' ][ 'id' ];
                $status = $member[ 'new_chat_member' ][ 'status' ];
                $unsub = ( $status == 'left' || $status == 'kicked' );
                
                if ( $unsub ) {
                    log_info( __FILE__, __LINE__, 'Unsubscribe chat_id for ' . $dispatcher . ': ' . print_r($chat_id, true));
                    $changes[] = ['unset' => [$dispatcher => ['tg-bot-subs' => [ $chat_id ]]]];
                }
                else {
                    log_info( __FILE__, __LINE__, 'Subscribe chat_id for ' . $dispatcher . ': ' . print_r($chat_id, true));
                    $changes[] = ['set' => [$dispatcher => ['tg-bot-subs' => [ $chat_id ]]]];
                }
            }
            elseif ( array_key_exists( 'message', $update ) ) {
                $message = $update[ 'message' ];
                $chat_id = $message[ 'chat' ][ 'id' ];
                $chat_type = $message[ 'chat' ][ 'type' ];
                $text = $message[ 'text' ] ?? '';
                if ( ( $chat_type == 'private' ) && ( $text == '/start' ) ) {
                    log_info( __FILE__, __LINE__, 'Subscribe by command chat_id for ' . $dispatcher . ': ' . print_r($chat_id, true));
                    $changes[] = ['set' => [$dispatcher => ['tg-bot-subs' => [ $chat_id ]]]];
                }
            }
        }
    }
    
    return update_json( STATE_PATH, $changes );
}

function dispatch( $dispatcher, $subject, $text, $files = [] ) {
    $config = read_json( CONFIG_PATH )[ $dispatcher ];
    $state = read_json( STATE_PATH )[ $dispatcher ];
    
    $mbox = imap_open('{' . $config[ 'imap' ] . '}INBOX', $config[ 'email' ], $config[ 'password' ]);
    $mailer = create_phpmailer( $config );
    
    dispatch_message( $dispatcher, $config, $state, $subject, $text, $files, $mbox, $mailer );
    
    imap_close( $mbox );
}

function check_mailboxes() {
    global $SUBSCRIBE_MSG, $UNSUBSCRIBE_MSG;

    $configs = read_json( CONFIG_PATH );
    
    foreach ( $configs as $dispatcher => $config ) {
        $state = read_json( STATE_PATH )[ $dispatcher ] ?? [];
        $changes = [];
        
        $imap = $config[ 'imap' ] ?? null;
        if ( is_null($imap) || ($config[ 'disabled' ] ?? false) )
            continue;
        
        log_debug( __FILE__, __LINE__, 'Checking dispatcher ' . $dispatcher );
        
        $mbox = imap_open('{' . $imap . '}INBOX', $config[ 'email' ], $config[ 'password' ]);
        $mailer = create_phpmailer( $config );
        $ftp = null;

        $src_email = $config[ 'src_email' ] ?? $config[ 'email' ];
        if ( $src_email == $config[ 'email' ] ) {
            log_debug( __FILE__, __LINE__, 'Fetching mails from single address ' . $config[ 'email' ] );
            $mails = process_incoming_mails( $mbox, $config[ 'sources' ] ?? [] );
            $src_mbox = null;
            $src_mails = $mails;
        }
        else {
            log_debug( __FILE__, __LINE__, 'Fetching sub/unsub mails from ' . $config[ 'email' ] );
            $mails = process_incoming_mails( $mbox, [] );
            $src_mbox = imap_open('{' . $imap . '}INBOX', $config[ 'src_email' ], $config[ 'src_password' ] ?? $config[ 'password' ]);
            log_debug( __FILE__, __LINE__, 'Fetching source mails from ' . $config[ 'src_email' ] );
            $src_mails = process_incoming_mails( $src_mbox, $config[ 'sources' ] ?? [] );
        }
        
        foreach ( ( $mails[ 'sub' ] ?? [] ) as $sub ) {
            $address = $sub[ 'address' ];
            if ( $sub[ 'sub' ] ) {
                $changes[] = [ 'set' => [$dispatcher => ['mail-subs' => [ $address ]]]];
                log_info( __FILE__, __LINE__, 'Subscribe ' . $address . ' to ' . $dispatcher );
                send_mail_generic( $mbox, $mailer, $config[ 'email' ], $address, $config[ 'subject' ],
                    ( in_array( $address, $state[ 'mail-subs' ] ?? [] ) ? 
                        'Вы уже подписаны на рассылку от ' :
                        'Вы подписались на рассылку от '
                    ) . $config[ 'email' ] . $UNSUBSCRIBE_MSG);
            }
            else {
                $changes[] = ['unset' => [$dispatcher => ['mail-subs' => [ $address ]]]];
                log_info( __FILE__, __LINE__, 'Unsubscribe ' . $address . ' from ' . $dispatcher );
                send_mail_generic( $mbox, $mailer, $config[ 'email' ], $address, $config[ 'subject' ],
                    ( in_array( $address, $state[ 'mail-subs' ] ?? [] ) ? 
                        'Вы отписались от рассылки от ' :
                        'Вы не подписаны на рассылку от ' 
                    ) . $config[ 'email' ] . $SUBSCRIBE_MSG);
            }
        }
        
        update_json( STATE_PATH, $changes );
        $state = read_json( STATE_PATH )[ $dispatcher ] ?? [];
        
        $attachs_dir = $config[ 'attachments-dir' ] ?? 'attachments';
        if ( is_null( $url_prefix = $config[ 'url-prefix' ] ?? null ) ) {
            if ( preg_match('/.*www\/(.*)/', basename( __FILE__ ), $match)) {
                $url_prefix = 'https://' . $match[1];
                log_debug( __FILE__, __LINE__, 'Autodetermined url prefix: ' . $url_prefix );
            }
        }
        
        foreach ( ( $src_mails[ 'dispatch' ] ?? [] ) as $mail ) {
            if ( count( $mail[ 'attachments' ] ) > 0 && is_null( $ftp ) && array_key_exists( 'ftp', $config ) ) {
                log_debug( __FILE__, __LINE__, 'Creating FTP connection to ' . $config[ 'ftp' ][ 'host' ] );
                $ftp = ftp_connect( $config[ 'ftp' ][ 'host' ] );
                ftp_login($ftp, $config[ 'ftp' ][ 'username' ], $config[ 'ftp' ][ 'password' ]);
                ftp_pasv($ftp, true);
            }
            
            $subject = '(' . $config[ 'subject' ] . ') ';
            if ( strlen( $mail[ 'subject' ] ) > 0 )
                $subject .= $mail[ 'subject' ];
            
            $text = $mail[ 'contents' ][ 'PLAIN' ] ?? $mail[ 'contents' ][ 'HTML' ] ?? '';
            $files = store_attachments( $mail[ 'attachments' ], $attachs_dir, $url_prefix );
            if ( ! is_null( $ftp ) && count( $files ) > 0 )
                upload_attachments( $files, $ftp, $config[ 'ftp' ][ 'dir' ] ?? $attachs_dir );
            
            dispatch_message( $dispatcher, $config, $state, $subject, $text, $files, $mbox, $mailer );
        }
        
        imap_close( $mbox );
        if ( ! is_null( $src_mbox ) )
            imap_close( $src_mbox );
        if ( ! is_null( $ftp ) )
            ftp_close( $ftp );
    }
}
