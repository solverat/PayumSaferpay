<?php declare(strict_types=1);

namespace Karser\PayumSaferpay\Tests\Functional;

use Karser\PayumSaferpay\Constants;
use Karser\PayumSaferpay\Request\Api\DeleteAlias;
use Payum\Core\Reply\HttpRedirect;

class CardAliasTest extends AbstractSaferpayTest
{
    /**
     * @test
     */
    public function insertAlias()
    {
        $cardAlias = $this->createCardAlias([
            'Alias' => [
                'IdGenerator' => Constants::ALIAS_ID_GENERATOR_MANUAL,
                'Id' => $generatedId = uniqid('id', true),
                'Lifetime' => self::ALIAS_LIFETIME,
            ]
        ]);
        $token = $this->payum->getTokenFactory()->createCaptureToken(self::GATEWAY_NAME, $cardAlias, 'done.php');
        $this->payum->getHttpRequestVerifier()->invalidate($token); //no need to store token

        $reply = $this->insertCardAlias($token, $cardAlias);

        #assert redirected
        self::assertInstanceOf(HttpRedirect::class, $reply);
        self::assertStringStartsWith('https://test.saferpay.com/', $iframeUrl = $reply->getUrl());

        # submit form
        $iframeRedirect = $this->getThroughCheckout($reply->getUrl(), $formData = $this->composeFormData(self::CARD_SUCCESS, $cvc = false));

        self::assertStringStartsWith(self::HOST, $iframeRedirect);
        self::assertContains('payum_token='.$token->getHash(), $iframeRedirect);
        self::assertContains('success=1', $iframeRedirect);
        parse_str(parse_url($iframeRedirect, PHP_URL_QUERY), $_GET);

        $this->insertCardAlias($token, $cardAlias);

        self::assertArraySubset([
            'Alias' => [
                'Id' => $generatedId,
                'Lifetime' => self::ALIAS_LIFETIME,
            ],
            'PaymentMeans' => ['Card' => [
                'MaskedNumber' => 'xxxxxxxxxxxx'.substr($formData['CardNumber'], -4),
                'ExpYear' => (int) $formData['ExpYear'],
                'ExpMonth' => (int) $formData['ExpMonth'],
                'HolderName' => $formData['HolderName'],
            ]],
        ], $cardAlias->getDetails());
    }

    /**
     * @test
     */
    public function deleteAlias()
    {
        $cardAlias = $this->createInsertedCardAlias([]);
        self::assertNotNull($cardAlias->getDetails()['Alias']['Id']);
        $this->gateway->execute(new DeleteAlias($cardAlias));
        self::assertNull($cardAlias->getDetails()['Alias']['Id']);
    }
}