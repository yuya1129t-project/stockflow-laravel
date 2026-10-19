<?php

/**
 * テスト用の最小の HTTP サーバー（127.0.0.1 の空いているポートで待ち受け、標準出力の 1 行目にポート番号を出す）。
 * 応答の形（Content-Length の有無・chunked・送る速さ）を細かく決めるため、ソケットに生の HTTP を書く。
 * 使い方: php raw-http-server.php <mode>
 *
 * - json-chunked: 正しい zipcloud の応答（031-0001）を chunked で返す
 * - large-nolength: Content-Length も chunked もない（接続を閉じて終わる）大きな本文を、16KB ずつ 50ms おきに送る
 * - large-chunked: 大きな本文を chunked で、16KB ずつ 50ms おきに送る
 * - large-length: Content-Length に 10MB と書き、16KB ずつ 50ms おきに送る
 * - slow: 正しい応答を chunked で、1 バイトずつ 300ms おきに送る（全体で 3 秒を超える）
 */
$mode = $argv[1] ?? 'json-chunked';
$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
if ($server === false) {
    fwrite(STDERR, "listen failed: {$errstr}\n");
    exit(1);
}
$name = (string) stream_socket_get_name($server, false);
echo substr($name, strrpos($name, ':') + 1)."\n";

$json = json_encode([
    'message' => null,
    'results' => [['zipcode' => '0310001', 'address1' => '青森県', 'address2' => '八戸市', 'address3' => '類家']],
    'status' => 200,
], JSON_UNESCAPED_UNICODE);

/** @param resource $conn */
function send($conn, string $data): bool
{
    return @fwrite($conn, $data) === strlen($data);
}

/** @param resource $conn */
function chunk($conn, string $data): bool
{
    return send($conn, dechex(strlen($data))."\r\n".$data."\r\n");
}

while (($conn = @stream_socket_accept($server, 60)) !== false) {
    // 要求のヘッダーを読み終えるまで読む
    $request = '';
    while (! str_contains($request, "\r\n\r\n") && ($line = fgets($conn)) !== false) {
        $request .= $line;
    }

    $block = str_repeat(' ', 16 * 1024);
    switch ($mode) {
        case 'json-chunked':
            send($conn, "HTTP/1.1 200 OK\r\nContent-Type: text/plain;charset=utf-8\r\nTransfer-Encoding: chunked\r\nConnection: close\r\n\r\n");
            foreach (str_split((string) $json, 10) as $part) {
                chunk($conn, $part);
            }
            send($conn, "0\r\n\r\n");
            break;
        case 'large-nolength':
            send($conn, "HTTP/1.1 200 OK\r\nContent-Type: text/plain\r\nConnection: close\r\n\r\n");
            for ($i = 0; $i < 200 && send($conn, $block); $i++) {
                usleep(50_000);
            }
            break;
        case 'large-chunked':
            send($conn, "HTTP/1.1 200 OK\r\nContent-Type: text/plain\r\nTransfer-Encoding: chunked\r\nConnection: close\r\n\r\n");
            for ($i = 0; $i < 200 && chunk($conn, $block); $i++) {
                usleep(50_000);
            }
            send($conn, "0\r\n\r\n");
            break;
        case 'large-length':
            send($conn, "HTTP/1.1 200 OK\r\nContent-Type: text/plain\r\nContent-Length: ".(10 * 1024 * 1024)."\r\nConnection: close\r\n\r\n");
            for ($i = 0; $i < 200 && send($conn, $block); $i++) {
                usleep(50_000);
            }
            break;
        case 'slow':
            send($conn, "HTTP/1.1 200 OK\r\nContent-Type: text/plain\r\nTransfer-Encoding: chunked\r\nConnection: close\r\n\r\n");
            foreach (str_split((string) $json) as $byte) {
                if (! chunk($conn, $byte)) {
                    break;
                }
                usleep(300_000);
            }
            send($conn, "0\r\n\r\n");
            break;
    }
    fclose($conn);
}
