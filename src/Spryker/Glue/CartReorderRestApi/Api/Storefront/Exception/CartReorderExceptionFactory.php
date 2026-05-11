<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

declare(strict_types=1);

namespace Spryker\Glue\CartReorderRestApi\Api\Storefront\Exception;

use Generated\Shared\Transfer\CartReorderResponseTransfer;
use Generated\Shared\Transfer\ErrorTransfer;
use Spryker\ApiPlatform\Exception\GlueApiException;
use Spryker\Client\GlossaryStorage\GlossaryStorageClientInterface;
use Spryker\Glue\CartReorderRestApi\CartReorderRestApiConfig;
use Symfony\Component\HttpFoundation\Response;

/**
 * Builds pre-configured `GlueApiException` instances for known cart-reorder error scenarios.
 *
 * Uses {@see CartReorderRestApiConfig::getErrorMessageToRestErrorMapping()} as the source of
 * truth for `glossaryKey → [code, status]` translation; falls back to the configured default
 * code/422 status when no mapping matches.
 */
class CartReorderExceptionFactory
{
    protected const string KEY_STATUS = 'status';

    protected const string KEY_CODE = 'code';

    public function __construct(
        protected CartReorderRestApiConfig $cartReorderRestApiConfig = new CartReorderRestApiConfig(),
        protected ?GlossaryStorageClientInterface $glossaryStorageClient = null,
    ) {
    }

    /**
     * @uses \Spryker\ApiPlatform\EventSubscriber\GlueApiExceptionSubscriber error code for
     *       denormalization-style validation failures.
     */
    protected const string ERROR_CODE_VALIDATION = '901';

    public function createOrderReferenceMissingException(): GlueApiException
    {
        return new GlueApiException(
            Response::HTTP_BAD_REQUEST,
            CartReorderRestApiConfig::ERROR_CODE_DEFAULT_CART_REORDER_FAILED,
            'Order reference is missing.',
        );
    }

    /**
     * Raised when the request body sends a non-boolean value for `isAmendment`
     * (e.g. an empty string). API Platform's typed-property denormalization silently
     * coerces such values to `null` for `?bool` properties; the legacy stack returned
     * 422 for the same input. This factory restores that contract.
     */
    public function createInvalidIsAmendmentTypeException(): GlueApiException
    {
        return new GlueApiException(
            Response::HTTP_UNPROCESSABLE_ENTITY,
            static::ERROR_CODE_VALIDATION,
            'isAmendment: This value should be of type bool.',
        );
    }

    /**
     * Builds a `GlueApiException` from the first error in a non-successful
     * `CartReorderResponseTransfer`. Looks up the glossary key in
     * {@see CartReorderRestApiConfig::getErrorMessageToRestErrorMapping()} and falls back
     * to the configured default error code.
     */
    public function createExceptionFromCartReorderResponse(
        CartReorderResponseTransfer $cartReorderResponseTransfer,
        string $localeName,
    ): GlueApiException {
        $errors = $cartReorderResponseTransfer->getErrors();

        if ($errors->count() === 0) {
            return new GlueApiException(
                Response::HTTP_UNPROCESSABLE_ENTITY,
                CartReorderRestApiConfig::ERROR_CODE_DEFAULT_CART_REORDER_FAILED,
                'Errors appeared during cart reordering.',
            );
        }

        /** @var \Generated\Shared\Transfer\ErrorTransfer $firstError */
        $firstError = $errors->offsetGet(0);

        return $this->mapErrorToGlueApiException($firstError, $localeName);
    }

    protected function mapErrorToGlueApiException(ErrorTransfer $errorTransfer, string $localeName): GlueApiException
    {
        $message = $errorTransfer->getMessageOrFail();
        $errorMessageToRestErrorMapping = $this->cartReorderRestApiConfig->getErrorMessageToRestErrorMapping();

        $detail = $this->glossaryStorageClient !== null
            ? $this->glossaryStorageClient->translate($message, $localeName, $errorTransfer->getParameters())
            : $message;

        if (isset($errorMessageToRestErrorMapping[$message])) {
            return new GlueApiException(
                (int)$errorMessageToRestErrorMapping[$message][static::KEY_STATUS],
                (string)$errorMessageToRestErrorMapping[$message][static::KEY_CODE],
                $detail,
            );
        }

        return new GlueApiException(
            Response::HTTP_UNPROCESSABLE_ENTITY,
            CartReorderRestApiConfig::ERROR_CODE_DEFAULT_CART_REORDER_FAILED,
            $detail,
        );
    }
}
