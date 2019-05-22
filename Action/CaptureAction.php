<?php declare(strict_types=1);

namespace Karser\PayumSaferpay\Action;

use Karser\PayumSaferpay\Constants;
use Karser\PayumSaferpay\Request\Api\AssertPaymentPage;
use Karser\PayumSaferpay\Request\Api\AuthorizeTransaction;
use Karser\PayumSaferpay\Request\Api\CaptureTransaction;
use Karser\PayumSaferpay\Request\Api\InitPaymentPage;
use Karser\PayumSaferpay\Request\Api\InitTransaction;
use League\Uri\Http as HttpUri;
use League\Uri\Modifiers\MergeQuery;
use Payum\Core\Action\ActionInterface;
use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\Exception\LogicException;
use Payum\Core\GatewayAwareInterface;
use Payum\Core\GatewayAwareTrait;
use Payum\Core\Request\Capture;
use Payum\Core\Exception\RequestNotSupportedException;
use Payum\Core\Request\GetHttpRequest;
use Payum\Core\Request\GetHumanStatus;
use Payum\Core\Security\GenericTokenFactoryAwareInterface;
use Payum\Core\Security\GenericTokenFactoryAwareTrait;
use Payum\Core\Security\TokenInterface;

final class CaptureAction implements ActionInterface, GatewayAwareInterface, GenericTokenFactoryAwareInterface
{
    use GatewayAwareTrait;
    use GenericTokenFactoryAwareTrait;

    /**
     * @param Capture $request
     */
    public function execute($request)
    {
        RequestNotSupportedException::assertSupports($this, $request);

        $model = ArrayObject::ensureArrayObject($request->getModel());

        $interface = $model['Interface'] ?? Constants::INTERFACE_TRANSACTION;
        switch ($interface) {
            case Constants::INTERFACE_PAYMENT_PAGE:
                $this->handlePaymentPageInterface($request);
                break;
            case Constants::INTERFACE_TRANSACTION:
                $this->handleTransactionInterface($request);
                break;
            default:
                throw new LogicException(sprintf('Unknown interface "%s"', $interface));
        }
    }

    private function handlePaymentPageInterface(Capture $request)
    {
        $model = ArrayObject::ensureArrayObject($request->getModel());

        if (empty($model['Token'])) {
            $token = $request->getToken();
            if (empty($model['ReturnUrls'])) {
                $model['ReturnUrls'] = $this->composeReturnUrls($token->getTargetUrl());
            }
            if (empty($model['Notification']['NotifyUrl'])) {
                $notifyToken = $this->tokenFactory->createNotifyToken($token->getGatewayName(), $token->getDetails());
                $model['Notification'] = array_merge($model['Notification'] ?? [], [
                    'NotifyUrl' => $notifyToken->getTargetUrl(),
                ]);
            }
            $this->gateway->execute(new InitPaymentPage($model)); //might throw redirect to iframe
        }

        $this->gateway->execute($httpRequest = new GetHttpRequest());

        if (isset($httpRequest->query['abort'])) {
            $model->replace(['Transaction' => array_merge($model['Transaction'], ['Status' => Constants::STATUS_ABORTED])]);
            return;
        }

        if (isset($httpRequest->query['fail'])) {
            $model->replace(['Transaction' => array_merge($model['Transaction'], ['Status' => Constants::STATUS_FAILED])]);
            return;
        }
        $this->gateway->execute(new AssertPaymentPage($model));

        $this->gateway->execute($status = new GetHumanStatus($model));
        if ($status->isAuthorized()) {
            $this->gateway->execute(new CaptureTransaction($model));
        }
    }

    private function handleTransactionInterface(Capture $request)
    {
        $model = ArrayObject::ensureArrayObject($request->getModel());

        if (empty($model['Token'])) {
            if (empty($model['ReturnUrls'])) {
                $token = $request->getToken();
                $model['ReturnUrls'] = $this->composeReturnUrls($token->getTargetUrl());
            }
            $this->gateway->execute(new InitTransaction($model)); //might throw redirect to iframe
        }

        $this->gateway->execute($httpRequest = new GetHttpRequest());

        if (isset($httpRequest->query['abort'])) {
            $model->replace(['Transaction' => array_merge($model['Transaction'], ['Status' => Constants::STATUS_ABORTED])]);
            return;
        }

        if (isset($httpRequest->query['fail'])) {
            $model->replace(['Transaction' => array_merge($model['Transaction'], ['Status' => Constants::STATUS_FAILED])]);
            return;
        }
        $this->gateway->execute(new AuthorizeTransaction($model));

        $this->gateway->execute($status = new GetHumanStatus($model));
        if ($status->isAuthorized()) {
            $this->gateway->execute(new CaptureTransaction($model));
        }
    }

    private function composeReturnUrls(string $url): array
    {
        $successUrl = HttpUri::createFromString($url);
        $modifier = new MergeQuery('success=1');
        $successUrl = $modifier->process($successUrl);

        $failedUrl = HttpUri::createFromString($url);
        $modifier = new MergeQuery('fail=1');
        $failedUrl = $modifier->process($failedUrl);

        $cancelUri = HttpUri::createFromString($url);
        $modifier = new MergeQuery('abort=1');
        $cancelUri = $modifier->process($cancelUri);

        return [
            'Success' => (string) $successUrl,
            'Fail' => (string) $failedUrl,
            'Abort' => (string) $cancelUri,
        ];
    }

    public function supports($request): bool
    {
        return
            $request instanceof Capture &&
            $request->getModel() instanceof \ArrayAccess &&
            $request->getToken() instanceof TokenInterface
        ;
    }
}