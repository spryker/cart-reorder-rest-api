<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

namespace Spryker\Glue\CartReorderRestApi\Processor\Creator;

use Generated\Shared\Transfer\CartReorderRequestTransfer;
use Generated\Shared\Transfer\RestCartReorderRequestAttributesTransfer;
use Generated\Shared\Transfer\RestUserTransfer;
use Spryker\Glue\CartReorderRestApi\Dependency\Client\CartReorderRestApiToCartReorderClientInterface;
use Spryker\Glue\CartReorderRestApi\Processor\Mapper\CartReorderRestRequestMapperInterface;
use Spryker\Glue\CartReorderRestApi\Processor\ResponseBuilder\CartReorderRestResponseBuilderInterface;
use Spryker\Glue\CartReorderRestApi\Processor\Validator\CartReorderRestRequestValidatorInterface;
use Spryker\Glue\GlueApplication\Rest\JsonApi\RestResponseInterface;
use Spryker\Glue\GlueApplication\Rest\Request\Data\RestRequestInterface;

class CartReorderCreator implements CartReorderCreatorInterface
{
    /**
     * @param \Spryker\Glue\CartReorderRestApi\Dependency\Client\CartReorderRestApiToCartReorderClientInterface $cartReorderClient
     * @param \Spryker\Glue\CartReorderRestApi\Processor\ResponseBuilder\CartReorderRestResponseBuilderInterface $cartReorderRestResponseBuilder
     * @param \Spryker\Glue\CartReorderRestApi\Processor\Validator\CartReorderRestRequestValidatorInterface $cartReorderRestRequestValidator
     * @param \Spryker\Glue\CartReorderRestApi\Processor\Mapper\CartReorderRestRequestMapperInterface $cartReorderRestRequestMapper
     * @param list<\Spryker\Glue\CartReorderRestApiExtension\Dependency\Plugin\CartReorderRequestExpanderPluginInterface> $cartReorderRequestExpanderPlugins
     */
    public function __construct(
        protected CartReorderRestApiToCartReorderClientInterface $cartReorderClient,
        protected CartReorderRestResponseBuilderInterface $cartReorderRestResponseBuilder,
        protected CartReorderRestRequestValidatorInterface $cartReorderRestRequestValidator,
        protected CartReorderRestRequestMapperInterface $cartReorderRestRequestMapper,
        protected array $cartReorderRequestExpanderPlugins
    ) {
    }

    public function reorder(
        RestRequestInterface $restRequest,
        RestCartReorderRequestAttributesTransfer $restCartReorderRequestAttributesTransfer
    ): RestResponseInterface {
        $validationGlueErrorTransfers = $this->cartReorderRestRequestValidator->validateRestRequestAttributes($restCartReorderRequestAttributesTransfer);
        if ($validationGlueErrorTransfers) {
            return $this->cartReorderRestResponseBuilder->buildRequestValidationErrorResponse($validationGlueErrorTransfers);
        }

        /** @var \Generated\Shared\Transfer\RestUserTransfer $restUserTransfer */
        $restUserTransfer = $restRequest->getRestUser();
        $cartReorderRequestTransfer = $this->cartReorderRestRequestMapper->mapRestCartReorderRequestAttributesToCartReorderRequest(
            $restCartReorderRequestAttributesTransfer,
            (new CartReorderRequestTransfer())->setCustomerReference($restUserTransfer->getNaturalIdentifierOrFail()),
        );
        $cartReorderRequestTransfer = $this->executeCartReorderRequestExpanderPlugins(
            $cartReorderRequestTransfer,
            $restUserTransfer,
        );

        $cartReorderResponseTransfer = $this->cartReorderClient->reorder($cartReorderRequestTransfer);

        return $cartReorderResponseTransfer->getErrors()->count()
            ? $this->cartReorderRestResponseBuilder->buildErrorResponse($cartReorderResponseTransfer, $restRequest->getMetadata()->getLocale())
            : $this->cartReorderRestResponseBuilder->buildSuccessfulResponse($cartReorderResponseTransfer, $restRequest);
    }

    protected function executeCartReorderRequestExpanderPlugins(
        CartReorderRequestTransfer $cartReorderRequestTransfer,
        RestUserTransfer $restUserTransfer
    ): CartReorderRequestTransfer {
        foreach ($this->cartReorderRequestExpanderPlugins as $cartReorderRequestExpanderPlugin) {
            $cartReorderRequestTransfer = $cartReorderRequestExpanderPlugin->expand($cartReorderRequestTransfer, $restUserTransfer);
        }

        return $cartReorderRequestTransfer;
    }
}
