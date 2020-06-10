<?php declare(strict_types=1);

namespace Karser\PayumSaferpay\Action\Api;

use ArrayAccess;
use Karser\PayumSaferpay\Constants;
use Karser\PayumSaferpay\Exception\SaferpayHttpException;
use Karser\PayumSaferpay\Request\Api\InitTransaction;
use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\Exception\LogicException;
use Payum\Core\Exception\RequestNotSupportedException;
use Payum\Core\Reply\HttpRedirect;
use Payum\Core\Reply\HttpResponse;
use Payum\Core\Request\RenderTemplate;

class InitTransactionAction extends BaseApiAwareAction
{
    /**
     * @param InitTransaction $request
     */
    public function execute($request): void
    {
        RequestNotSupportedException::assertSupports($this, $request);
        $model = ArrayObject::ensureArrayObject($request->getModel());

        if (empty($model['Payment'])) {
            throw new LogicException('Payment is missing');
        }
        if (empty($model['ReturnUrls'])) {
            throw new LogicException('ReturnUrls is missing');
        }

        if (empty($model['Token'])) {
            try {
                $response = $this->api->initTransaction((array) $model);
                $model->replace(['Transaction' => array_merge($model['Transaction'], ['Status' => Constants::STATUS_PENDING])]);
                $model['Token'] = $response['Token'];
                $model['Expiration'] = $response['Expiration'];
                $model['RedirectRequired'] = $response['RedirectRequired'];
                if ($response['RedirectRequired']) {
                    $model['Redirect'] = $response['Redirect'];
                }
            } catch (SaferpayHttpException $e) {
                $model['Error'] = $e->toArray();
                $model->replace(['Transaction' => array_merge($model['Transaction'], ['Status' => Constants::STATUS_FAILED])]);
                return;
            }
        }

        if (!$model['RedirectRequired']) {
            return;
        }

        // render template, if required
        //if($this->api->hasTemplate()) {}

        if (true) {
            $this->gateway->execute($renderTemplate = new RenderTemplate('@PayumSaferPay/Action/obtain_checkout_token.html.twig', [
                'model'     => $model,
                'frame_src' => $model['Redirect']['RedirectUrl'],
            ]));

            throw new HttpResponse($renderTemplate->getResult());
        }

        throw new HttpRedirect($model['Redirect']['RedirectUrl']);
    }

    public function supports($request): bool
    {
        return
            $request instanceof InitTransaction &&
            $request->getModel() instanceof ArrayAccess
        ;
    }
}
