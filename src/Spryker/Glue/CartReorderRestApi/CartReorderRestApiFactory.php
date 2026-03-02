<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

namespace Spryker\Glue\CartReorderRestApi;

use Spryker\Glue\CartReorderRestApi\Dependency\Client\CartReorderRestApiToCartReorderClientInterface;
use Spryker\Glue\CartReorderRestApi\Dependency\Client\CartReorderRestApiToGlossaryStorageClientInterface;
use Spryker\Glue\CartReorderRestApi\Dependency\Glue\CartReorderRestApiToCartsRestApiResourceInterface;
use Spryker\Glue\CartReorderRestApi\Processor\Creator\CartReorderCreator;
use Spryker\Glue\CartReorderRestApi\Processor\Creator\CartReorderCreatorInterface;
use Spryker\Glue\CartReorderRestApi\Processor\Mapper\CartReorderRestErrorMapper;
use Spryker\Glue\CartReorderRestApi\Processor\Mapper\CartReorderRestErrorMapperInterface;
use Spryker\Glue\CartReorderRestApi\Processor\Mapper\CartReorderRestRequestMapper;
use Spryker\Glue\CartReorderRestApi\Processor\Mapper\CartReorderRestRequestMapperInterface;
use Spryker\Glue\CartReorderRestApi\Processor\ResponseBuilder\CartReorderRestResponseBuilder;
use Spryker\Glue\CartReorderRestApi\Processor\ResponseBuilder\CartReorderRestResponseBuilderInterface;
use Spryker\Glue\CartReorderRestApi\Processor\Validator\CartReorderRestRequestValidator;
use Spryker\Glue\CartReorderRestApi\Processor\Validator\CartReorderRestRequestValidatorInterface;
use Spryker\Glue\Kernel\AbstractFactory;

/**
 * @method \Spryker\Glue\CartReorderRestApi\CartReorderRestApiConfig getConfig()
 */
class CartReorderRestApiFactory extends AbstractFactory
{
    public function createCartReorderCreator(): CartReorderCreatorInterface
    {
        return new CartReorderCreator(
            $this->getCartReorderClient(),
            $this->createCartReorderRestResponseBuilder(),
            $this->createCartReorderRestRequestValidator(),
            $this->createCartReorderRestRequestMapper(),
            $this->getCartReorderRequestExpanderPlugins(),
        );
    }

    public function createCartReorderRestResponseBuilder(): CartReorderRestResponseBuilderInterface
    {
        return new CartReorderRestResponseBuilder(
            $this->getCartsRestApiResource(),
            $this->getResourceBuilder(),
            $this->createCartReorderRestErrorMapper(),
        );
    }

    public function createCartReorderRestRequestValidator(): CartReorderRestRequestValidatorInterface
    {
        return new CartReorderRestRequestValidator(
            $this->getRestCartReorderAttributesValidatorPlugins(),
        );
    }

    public function createCartReorderRestRequestMapper(): CartReorderRestRequestMapperInterface
    {
        return new CartReorderRestRequestMapper(
            $this->getRestCartReorderAttributesMapperPlugins(),
        );
    }

    public function createCartReorderRestErrorMapper(): CartReorderRestErrorMapperInterface
    {
        return new CartReorderRestErrorMapper(
            $this->getConfig(),
            $this->getGlossaryStorageClient(),
        );
    }

    public function getCartReorderClient(): CartReorderRestApiToCartReorderClientInterface
    {
        return $this->getProvidedDependency(CartReorderRestApiDependencyProvider::CLIENT_CART_REORDER);
    }

    public function getGlossaryStorageClient(): CartReorderRestApiToGlossaryStorageClientInterface
    {
        return $this->getProvidedDependency(CartReorderRestApiDependencyProvider::CLIENT_GLOSSARY_STORAGE);
    }

    public function getCartsRestApiResource(): CartReorderRestApiToCartsRestApiResourceInterface
    {
        return $this->getProvidedDependency(CartReorderRestApiDependencyProvider::RESOURCE_CARTS_REST_API);
    }

    /**
     * @return list<\Spryker\Glue\CartReorderRestApiExtension\Dependency\Plugin\RestCartReorderAttributesMapperPluginInterface>
     */
    public function getRestCartReorderAttributesMapperPlugins(): array
    {
        return $this->getProvidedDependency(CartReorderRestApiDependencyProvider::PLUGINS_REST_CART_REORDER_ATTRIBUTES_MAPPER);
    }

    /**
     * @return list<\Spryker\Glue\CartReorderRestApiExtension\Dependency\Plugin\RestCartReorderAttributesValidatorPluginInterface>
     */
    public function getRestCartReorderAttributesValidatorPlugins(): array
    {
        return $this->getProvidedDependency(CartReorderRestApiDependencyProvider::PLUGINS_REST_CART_REORDER_ATTRIBUTES_VALIDATOR);
    }

    /**
     * @return list<\Spryker\Glue\CartReorderRestApiExtension\Dependency\Plugin\CartReorderRequestExpanderPluginInterface>
     */
    protected function getCartReorderRequestExpanderPlugins(): array
    {
        return $this->getProvidedDependency(CartReorderRestApiDependencyProvider::PLUGINS_CART_REORDER_REQUEST_EXPANDER);
    }
}
