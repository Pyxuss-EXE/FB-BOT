<?php

namespace FBBot;

class MockFacebookChecker implements FacebookCheckerInterface
{
    public function checkNumber(string $phone): array
    {
        // Simulate processing delay
        sleep(2);
        
        // Mock logic: random result
        $rand = mt_rand(1, 10);
        
        if ($rand <= 4) {
            return [
                'status' => 'valid',
                'message' => 'Number exists, SMS sent successfully',
                'account' => 'John Doe'
            ];
        } elseif ($rand <= 7) {
            return [
                'status' => 'multi',
                'message' => 'Multiple accounts found, random one selected',
                'accounts' => ['John Doe', 'Jane Doe', 'Bob Smith'],
                'selected' => 'Jane Doe'
            ];
        } elseif ($rand <= 9) {
            return [
                'status' => 'invalid',
                'message' => 'Number not registered on Facebook'
            ];
        } else {
            return [
                'status' => 'error',
                'message' => 'CAPTCHA encountered or rate limited'
            ];
        }
    }
}