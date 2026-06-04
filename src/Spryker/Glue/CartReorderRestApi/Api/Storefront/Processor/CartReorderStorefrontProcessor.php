<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

declare(strict_types=1);

namespace Spryker\Glue\CartReorderRestApi\Api\Storefront\Processor;

use Generated\Api\Storefront\CartReorderStorefrontResource;
use Generated\Api\Storefront\CartsStorefrontResource;
use Generated\Shared\Transfer\CartReorderRequestTransfer;
use Generated\Shared\Transfer\QuoteCriteriaFilterTransfer;
use Generated\Shared\Transfer\QuoteTransfer;
use Spryker\ApiPlatform\EventSubscriber\JsonApiRequestValidatorSubscriber;
use Spryker\ApiPlatform\State\Processor\AbstractStorefrontProcessor;
use Spryker\Client\CartReorder\CartReorderClientInterface;
use Spryker\Client\CartsRestApi\CartsRestApiClientInterface;
use Spryker\Glue\CartReorderRestApi\Api\Storefront\Exception\CartReorderExceptionFactory;
use Spryker\Glue\CartReorderRestApi\CartReorderRestApiConfig;
use Spryker\Glue\CartsRestApi\Api\Storefront\Mapper\StorefrontCartMapperInterface;
use Spryker\Glue\GlueApplication\Compatibility\RequestBuilder\SyntheticRestRequestBuilderInterface;
use Spryker\Service\Container\Attributes\Plugins;
use Spryker\Service\Serializer\SerializerServiceInterface;

class CartReorderStorefrontProcessor extends AbstractStorefrontProcessor
{
    protected const string KEY_ORDER_REFERENCE = 'orderReference';

    protected const string KEY_REORDER_STRATEGY = 'reorderStrategy';

    protected const string KEY_IS_AMENDMENT = 'isAmendment';

    /**
     * @uses \Spryker\Zed\MultiCart\Communication\Plugin\CartReorder\NewPersistentCartReorderQuoteProviderStrategyPlugin::REORDER_STRATEGY_NEW
     */
    protected const string REORDER_STRATEGY_NEW = 'new';

    /**
     * @param array<\Spryker\Glue\CartReorderRestApiExtension\Dependency\Plugin\CartReorderRequestExpanderPluginInterface> $cartReorderRequestExpanderPlugins
     */
    public function __construct(
        protected CartReorderClientInterface $cartReorderClient,
        protected CartsRestApiClientInterface $cartsRestApiClient,
        protected StorefrontCartMapperInterface $cartMapper,
        protected SerializerServiceInterface $serializer,
        protected SyntheticRestRequestBuilderInterface $syntheticRestRequestBuilder,
        protected CartReorderExceptionFactory $exceptionFactory = new CartReorderExceptionFactory(),
        #[Plugins(dependencyProviderMethod: 'getCartReorderRequestExpanderPlugins')]
        protected array $cartReorderRequestExpanderPlugins = [],
    ) {
    }

    /**
     * @throws \Spryker\ApiPlatform\Exception\GlueApiException
     */
    protected function processPost(mixed $data): mixed
    {
        $this->assertIsAmendmentRawValueIsValid();

        $orderReference = $this->extractOrderReferenceFromData($data);
        $reorderStrategy = $this->extractReorderStrategyFromData($data);
        $isAmendment = $this->extractIsAmendmentFromData($data);
        $customerReference = $this->getCustomerReference();

        $cartReorderRequestTransfer = (new CartReorderRequestTransfer())
            ->setOrderReference($orderReference)
            ->setCustomerReference($customerReference)
            ->setReorderStrategy($reorderStrategy)
            ->setIsAmendment($isAmendment);

        $cartReorderRequestTransfer = $this->executeCartReorderRequestExpanderPlugins($cartReorderRequestTransfer);

        $cartReorderResponseTransfer = $this->cartReorderClient->reorder($cartReorderRequestTransfer);

        if ($cartReorderResponseTransfer->getErrors()->count() > 0) {
            throw $this->exceptionFactory->createExceptionFromCartReorderResponse(
                $cartReorderResponseTransfer,
                $this->getLocale()->getLocaleNameOrFail(),
            );
        }

        $quoteTransfer = $this->resolveQuoteUuid(
            $cartReorderResponseTransfer->getQuoteOrFail(),
            $customerReference,
            $reorderStrategy,
            $orderReference,
        );

        return $this->mapQuoteTransferToCartsResource($quoteTransfer);
    }

    /**
     * The cart-reorder Zed flow does not always populate `uuid`/`idQuote` on the returned quote
     * (see {@see \Spryker\Zed\CartReorder\Business\Adder\CartItemAdder::addToCart()} — the
     * QuoteResponseTransfer comes back with items but stripped identifiers). Legacy Glue REST
     * accepted `data.id = null`; API Platform requires a non-empty IRI for the response.
     *
     * Resolution order:
     * 1. If the response quote already has a uuid, use it (happens when downstream Zed plugins
     *    re-attach the persisted quote — e.g. {@see \Spryker\Zed\PersistentCart\Communication\Plugin\CartReorder\UpdateQuoteCartPostReorderPlugin}).
     * 2. Otherwise, look up the customer's persistent cart matching the executed strategy via
     *    `CartsRestApiClient::getQuoteCollection()`:
     *    - `new` strategy creates a fresh non-default cart — pick the most recent non-default.
     *    - default/`replace` strategies reuse the customer's default cart — pick `isDefault`.
     * 3. As a last resort, derive a stable id from `orderReference` so the IRI converter can
     *    serialize the response without a hard error.
     */
    protected function resolveQuoteUuid(
        QuoteTransfer $quoteTransfer,
        string $customerReference,
        ?string $reorderStrategy,
        string $orderReference,
    ): QuoteTransfer {
        if ($quoteTransfer->getUuid() !== null && $quoteTransfer->getUuid() !== '') {
            return $quoteTransfer;
        }

        $quoteCollectionTransfer = $this->cartsRestApiClient->getQuoteCollection(
            (new QuoteCriteriaFilterTransfer())->setCustomerReference($customerReference),
        );

        $candidateQuoteTransfer = $reorderStrategy === static::REORDER_STRATEGY_NEW
            ? $this->findMostRecentNonDefaultQuote($quoteCollectionTransfer->getQuotes())
            : $this->findDefaultQuote($quoteCollectionTransfer->getQuotes());

        if ($candidateQuoteTransfer === null) {
            $candidateQuoteTransfer = $this->findMostRecentQuote($quoteCollectionTransfer->getQuotes());
        }

        if ($candidateQuoteTransfer !== null) {
            return $quoteTransfer
                ->setUuid($candidateQuoteTransfer->getUuid())
                ->setCustomer($candidateQuoteTransfer->getCustomer());
        }

        return $quoteTransfer->setUuid($orderReference);
    }

    /**
     * @param iterable<\Generated\Shared\Transfer\QuoteTransfer> $quoteTransfers
     */
    protected function findDefaultQuote(iterable $quoteTransfers): ?QuoteTransfer
    {
        foreach ($quoteTransfers as $candidateQuoteTransfer) {
            if ($candidateQuoteTransfer->getIsDefault() === true && $candidateQuoteTransfer->getUuid() !== null) {
                return $candidateQuoteTransfer;
            }
        }

        return null;
    }

    /**
     * @param iterable<\Generated\Shared\Transfer\QuoteTransfer> $quoteTransfers
     */
    protected function findMostRecentNonDefaultQuote(iterable $quoteTransfers): ?QuoteTransfer
    {
        $mostRecentQuoteTransfer = null;
        foreach ($quoteTransfers as $candidateQuoteTransfer) {
            if ($candidateQuoteTransfer->getIsDefault() === true || $candidateQuoteTransfer->getUuid() === null) {
                continue;
            }

            if ($mostRecentQuoteTransfer === null || ($candidateQuoteTransfer->getIdQuote() ?? 0) > ($mostRecentQuoteTransfer->getIdQuote() ?? 0)) {
                $mostRecentQuoteTransfer = $candidateQuoteTransfer;
            }
        }

        return $mostRecentQuoteTransfer;
    }

    /**
     * @param iterable<\Generated\Shared\Transfer\QuoteTransfer> $quoteTransfers
     */
    protected function findMostRecentQuote(iterable $quoteTransfers): ?QuoteTransfer
    {
        $mostRecentQuoteTransfer = null;
        foreach ($quoteTransfers as $candidateQuoteTransfer) {
            if ($candidateQuoteTransfer->getUuid() === null) {
                continue;
            }

            if ($mostRecentQuoteTransfer === null || ($candidateQuoteTransfer->getIdQuote() ?? 0) > ($mostRecentQuoteTransfer->getIdQuote() ?? 0)) {
                $mostRecentQuoteTransfer = $candidateQuoteTransfer;
            }
        }

        return $mostRecentQuoteTransfer;
    }

    protected function extractReorderStrategyFromData(mixed $data): ?string
    {
        if ($data instanceof CartReorderStorefrontResource) {
            return $data->reorderStrategy;
        }

        if (is_array($data) && isset($data[static::KEY_REORDER_STRATEGY]) && is_string($data[static::KEY_REORDER_STRATEGY])) {
            return $data[static::KEY_REORDER_STRATEGY];
        }

        return null;
    }

    protected function extractIsAmendmentFromData(mixed $data): ?bool
    {
        if ($data instanceof CartReorderStorefrontResource) {
            return $data->isAmendment;
        }

        if (is_array($data) && isset($data[static::KEY_IS_AMENDMENT])) {
            return (bool)$data[static::KEY_IS_AMENDMENT];
        }

        return null;
    }

    /**
     * @throws \Spryker\ApiPlatform\Exception\GlueApiException
     */
    protected function extractOrderReferenceFromData(mixed $data): string
    {
        $orderReference = $this->resolveOrderReferenceFromData($data);

        if (!is_string($orderReference) || $orderReference === '') {
            throw $this->exceptionFactory->createOrderReferenceMissingException();
        }

        return $orderReference;
    }

    protected function resolveOrderReferenceFromData(mixed $data): mixed
    {
        if ($data instanceof CartReorderStorefrontResource) {
            return $data->orderReference;
        }

        if (is_array($data)) {
            return $data[static::KEY_ORDER_REFERENCE] ?? null;
        }

        return null;
    }

    /**
     * Restores the legacy contract where a non-boolean `isAmendment` value (most commonly an
     * empty string) is rejected with 422 before any reorder logic runs. API Platform's typed
     * `?bool` denormalization silently coerces such inputs to `null`, which would let the
     * processor proceed as a non-amendment reorder and return 201 instead.
     *
     * @throws \Spryker\ApiPlatform\Exception\GlueApiException
     */
    protected function assertIsAmendmentRawValueIsValid(): void
    {
        $sanitizedFields = $this->getRequest()->attributes->get(
            JsonApiRequestValidatorSubscriber::ATTRIBUTE_SANITIZED_EMPTY_STRING_FIELDS,
            [],
        );

        if (!is_array($sanitizedFields) || !in_array(static::KEY_IS_AMENDMENT, $sanitizedFields, true)) {
            return;
        }

        throw $this->exceptionFactory->createInvalidIsAmendmentTypeException();
    }

    protected function executeCartReorderRequestExpanderPlugins(
        CartReorderRequestTransfer $cartReorderRequestTransfer
    ): CartReorderRequestTransfer {
        if ($this->cartReorderRequestExpanderPlugins === []) {
            return $cartReorderRequestTransfer;
        }

        $restUserTransfer = $this->syntheticRestRequestBuilder->build(
            $this->getRequest(),
            $this->getCustomer(),
            CartReorderRestApiConfig::RESOURCE_CART_REORDER,
        )->getRestUser();

        if ($restUserTransfer === null) {
            return $cartReorderRequestTransfer;
        }

        foreach ($this->cartReorderRequestExpanderPlugins as $cartReorderRequestExpanderPlugin) {
            $cartReorderRequestTransfer = $cartReorderRequestExpanderPlugin->expand(
                $cartReorderRequestTransfer,
                $restUserTransfer,
            );
        }

        return $cartReorderRequestTransfer;
    }

    protected function mapQuoteTransferToCartsResource(QuoteTransfer $quoteTransfer): CartsStorefrontResource
    {
        $restCartsAttributesTransfer = $this->cartMapper->mapQuoteTransferToRestCartsAttributesTransfer($quoteTransfer);

        $resource = $this->serializer->denormalize(
            $restCartsAttributesTransfer->toArray(true, true),
            CartsStorefrontResource::class,
        );

        $resource->uuid = $quoteTransfer->getUuid();
        $resource->voucherDiscounts = iterator_to_array($quoteTransfer->getVoucherDiscounts());
        $resource->cartRuleDiscounts = iterator_to_array($quoteTransfer->getCartRuleDiscounts());
        $resource->promotionItems = iterator_to_array($quoteTransfer->getPromotionItems());
        $resource->giftCards = iterator_to_array($quoteTransfer->getGiftCards());
        $resource->bundleItems = iterator_to_array($quoteTransfer->getBundleItems());
        $resource->items = iterator_to_array($quoteTransfer->getItems());

        return $resource;
    }
}
