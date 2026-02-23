<?php
namespace FBBot;

class RealFacebookChecker implements FacebookCheckerInterface
{
    private $logger;
    private $cookieFile;
    private $userAgent;
    private $maxRetries;
    private $lastUrl = '';

    public function __construct()
    {
        $this->logger = Logger::getInstance();
        $this->cookieFile = tempnam(sys_get_temp_dir(), 'fb_cookie_');
        $this->userAgent = 'Mozilla/5.0 (Linux; Android 10; SM-G973F) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/83.0.4103.106 Mobile Safari/537.36';
        $this->maxRetries = getenv('MAX_RETRIES') ?: 3;
    }

    public function __destruct()
    {
        if (file_exists($this->cookieFile)) unlink($this->cookieFile);
    }

    public function checkNumber(string $phone): array
    {
        $this->logger->info("Starting check for $phone");
        $attempt = 0;
        while ($attempt < $this->maxRetries) {
            try {
                return $this->executeCheck($phone);
            } catch (\Exception $e) {
                $attempt++;
                $this->logger->warning("Attempt $attempt failed for $phone: " . $e->getMessage());
                if ($attempt >= $this->maxRetries) {
                    return ['status' => 'error', 'message' => 'Max retries: ' . $e->getMessage()];
                }
                sleep(2);
            }
        }
        return ['status' => 'error', 'message' => 'Unknown error'];
    }

    private function executeCheck(string $phone): array
    {
        $html = $this->httpGet('https://m.facebook.com/login/identify/?ctx=recover&ars=facebook_login&from_login_screen=0&__mmr=1&_rdr');
        
        $lsd = $this->extractLsd($html);
        $jazoest = $this->extractJazoest($html);

        $postData = [
            'lsd' => $lsd,
            'jazoest' => $jazoest,
            'email' => $phone,
            'did_submit' => 'Search'
        ];
        
        $postUrl = 'https://m.facebook.com/login/identify/?ctx=recover&c=%2Flogin%2F&search_attempts=1&ars=facebook_login&alternate_search=0&show_friend_search_filtered_list=0&birth_month_search=0&city_search=0';
        $response = $this->httpPost($postUrl, $postData, ['referer' => 'https://m.facebook.com/login/identify/?ctx=recover&ars=facebook_login&from_login_screen=0&__mmr=1&_rdr']);

        $body = $response['body'];
        $finalUrl = $response['url'];

        if (strpos($body, 'id="login_identify_search_error_msg"') !== false) {
            return ['status' => 'invalid', 'message' => 'Account not found'];
        }

        if (strpos($body, 'action="/login/identify/?ctx=recover') !== false) {
            $accounts = $this->extractMultipleAccounts($body);
            if (empty($accounts)) {
                return ['status' => 'multi', 'message' => 'Multiple accounts found', 'accounts' => []];
            }
            return ['status' => 'multi', 'message' => 'Multiple accounts - manual selection needed', 'accounts' => $accounts];
        }

        if (strpos($finalUrl, '/login/account_recovery/name_search/') !== false) {
            $response = $this->httpGet($finalUrl, ['referer' => 'https://m.facebook.com/login/identify/?ctx=recover&ars=facebook_login&from_login_screen=0&__mmr=1&_rdr']);
            $body = $response['body'];
        } elseif (strpos($finalUrl, '/login/device-based/ar/login/?ldata=') !== false) {
            $response = $this->httpGet($finalUrl, ['referer' => 'https://m.facebook.com/login/identify/?ctx=recover&ars=facebook_login&from_login_screen=0&__mmr=1&_rdr']);
            $body = $response['body'];
        }

        if (preg_match('/id="contact_point_selector_form".*?name="recover_method"/s', $body)) {
            return $this->handleSmsOption($body, $phone);
        } elseif (strpos($body, 'name="captcha_response"') !== false) {
            return ['status' => 'error', 'message' => 'CAPTCHA encountered'];
        } elseif (strpos($body, '/help/121104481304395') !== false || strpos($body, '/help/103873106370583') !== false) {
            return ['status' => 'invalid', 'message' => 'Account disabled'];
        } else {
            return ['status' => 'error', 'message' => 'Unknown response'];
        }
    }

    private function extractMultipleAccounts(string $html): array
    {
        $accounts = [];
        if (preg_match_all('/<div class="_52jc _52j9">(.*?)<\/div>/s', $html, $matches)) {
            foreach ($matches[1] as $name) {
                $name = strip_tags($name);
                if (!empty($name) && !in_array($name, $accounts)) {
                    $accounts[] = $name;
                }
            }
        }
        return $accounts;
    }

    private function handleSmsOption(string $html, string $phone): array
    {
        preg_match_all('/<input type="radio" name="recover_method" value="(send_sms:[^"]+)".*?id="([^"]+)"/', $html, $matches, PREG_SET_ORDER);
        $targetValue = null;
        $selectedDisplay = '';

        foreach ($matches as $match) {
            $value = $match[1];
            $inputId = $match[2];
            if (preg_match('/<label for="' . preg_quote($inputId, '/') . '".*?<div class="_52jc _52j9">(.*?)<\/div>/s', $html, $labelMatch)) {
                $displayText = strip_tags($labelMatch[1]);
                $displayDigits = preg_replace('/\D/', '', $displayText);
                if (!empty($displayDigits) && (strpos($phone, $displayDigits) !== false || substr($phone, -strlen($displayDigits)) === $displayDigits)) {
                    $targetValue = $value;
                    $selectedDisplay = $displayText;
                    break;
                }
            }
        }

        if (!$targetValue) {
            if (preg_match('#href="(/recover/initiate/\?privacy_mutation_token=[^"]+)"#', $html, $matches)) {
                $tryUrl = 'https://m.facebook.com' . str_replace('&amp;', '&', $matches[1]);
                $response = $this->httpGet($tryUrl, ['referer' => $this->lastUrl]);
                return $this->handleSmsOption($response['body'], $phone);
            }
            return ['status' => 'invalid', 'message' => 'No SMS option available'];
        }

        preg_match('/name="lsd" value="([^"]+)"/', $html, $lsdMatch);
        preg_match('/name="jazoest" value="([^"]+)"/', $html, $jazoestMatch);
        if (empty($lsdMatch) || empty($jazoestMatch)) {
            return ['status' => 'error', 'message' => 'Could not extract tokens for SMS'];
        }
        
        $lsd = $lsdMatch[1];
        $jazoest = $jazoestMatch[1];

        $action = '';
        if (preg_match('/<form.*?action="([^"]+)".*?id="contact_point_selector_form"/s', $html, $actionMatch)) {
            $action = $actionMatch[1];
        } else {
            $action = '/ajax/recover/initiate/';
        }
        $action = str_replace('&amp;', '&', $action);
        if (strpos($action, 'http') !== 0) {
            $action = 'https://m.facebook.com' . $action;
        }

        $postData = [
            'lsd' => $lsd,
            'jazoest' => $jazoest,
            'recover_method' => $targetValue,
            'reset_action' => 'Continue'
        ];

        $params = [
            'c' => '/login/',
            'ctx' => 'initate_view',
            'sr' => '0',
            'ars' => 'facebook_login'
        ];

        $fullUrl = $action . (strpos($action, '?') === false ? '?' : '&') . http_build_query($params);
        $response = $this->httpPost($fullUrl, $postData, ['referer' => $this->lastUrl]);
        $finalResponse = $this->httpGet($response['url'], ['referer' => $fullUrl]);
        $finalBody = $finalResponse['body'];

        if (strpos($finalBody, 'action="/recover/code/') !== false || strpos($finalBody, 'name="n"') !== false) {
            return [
                'status' => 'valid',
                'message' => 'OTP sent successfully',
                'account' => $selectedDisplay
            ];
        } elseif (strpos($finalBody, 'name="captcha_response"') !== false) {
            return ['status' => 'error', 'message' => 'CAPTCHA required'];
        } elseif (strpos($finalBody, '/r.php?next=') !== false) {
            return ['status' => 'error', 'message' => 'Redirect to signup'];
        } else {
            return ['status' => 'error', 'message' => 'Failed to send OTP'];
        }
    }

    private function extractLsd(string $html): string
    {
        if (preg_match('/name="lsd" value="([^"]+)"/', $html, $m)) return $m[1];
        if (preg_match('/\["LSD",\[\],\{"token":"([^"]+)"\}/', $html, $m)) return $m[1];
        throw new \Exception('LSD not found');
    }

    private function extractJazoest(string $html): string
    {
        if (preg_match('/name="jazoest" value="([^"]+)"/', $html, $m)) return $m[1];
        if (preg_match('/"initSprinkleValue":"([^"]+)"/', $html, $m)) return $m[1];
        throw new \Exception('Jazoest not found');
    }

    private function httpGet(string $url, array $extraHeaders = []): array
    {
        return $this->request('GET', $url, null, $extraHeaders);
    }

    private function httpPost(string $url, array $data, array $extraHeaders = []): array
    {
        return $this->request('POST', $url, http_build_query($data), $extraHeaders);
    }

    private function request(string $method, string $url, ?string $postFields = null, array $extraHeaders = []): array
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADER => true,
            CURLOPT_COOKIEFILE => $this->cookieFile,
            CURLOPT_COOKIEJAR => $this->cookieFile,
            CURLOPT_USERAGENT => $this->userAgent,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
        ]);

        $headers = [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
            'Accept-Language: en-US,en;q=0.9',
            'Cache-Control: max-age=0',
            'Connection: keep-alive',
            'Upgrade-Insecure-Requests: 1',
        ];

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
            $headers[] = 'Content-Length: ' . strlen($postFields);
        }

        if (isset($extraHeaders['referer'])) {
            $headers[] = 'Referer: ' . $extraHeaders['referer'];
        }

        foreach ($extraHeaders as $key => $value) {
            if ($key !== 'referer') $headers[] = "$key: $value";
        }

        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $response = curl_exec($ch);
        if (curl_errno($ch)) throw new \Exception('cURL error: ' . curl_error($ch));

        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $headersRaw = substr($response, 0, $headerSize);
        $body = substr($response, $headerSize);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $effectiveUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        curl_close($ch);

        if ($httpCode >= 300 && $httpCode < 400 && preg_match('/Location: ([^\r\n]+)/i', $headersRaw, $matches)) {
            $location = trim($matches[1]);
            if (strpos($location, 'http') !== 0) {
                $location = $this->resolveUrl($effectiveUrl, $location);
            }
            return $this->httpGet($location, ['referer' => $effectiveUrl]);
        }

        $this->lastUrl = $effectiveUrl;
        return ['url' => $effectiveUrl, 'body' => $body, 'headers' => $headersRaw, 'code' => $httpCode];
    }

    private function resolveUrl(string $base, string $relative): string
    {
        return $base . $relative;
    }
}