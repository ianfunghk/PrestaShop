<?php

namespace PrestaShop\PrestaShop\Core\Business\Product;

use Adapter_ObjectSerializer;
use Adapter_ImageRetriever;
use Adapter_PricePresenter;
use Adapter_ProductPriceCalculator;
use Adapter_ProductColorsRetriever;
use PrestaShop\PrestaShop\Core\Business\Price\PricePresenterInterface;
use Product;
use Language;
use Link;

class ProductPresenter
{
    private $imageRetriever;
    private $link;
    private $pricePresenter;
    private $productPriceCalculator;
    private $productColorsRetriever;

    public function __construct(
        Adapter_ImageRetriever $imageRetriever,
        Link $link,
        Adapter_PricePresenter $pricePresenter,
        Adapter_ProductColorsRetriever $productColorsRetriever
    ) {
        $this->imageRetriever = $imageRetriever;
        $this->link = $link;
        $this->pricePresenter = $pricePresenter;
        $this->productColorsRetriever = $productColorsRetriever;
    }

    private function shouldShowPrice(
        ProductPresentationSettings $settings,
        array $product
    ) {
        return  !$settings->catalog_mode &&
                !$settings->restricted_country_mode &&
                $product['available_for_order']
        ;
    }

    private function fillImages(
        array $presentedProduct,
        ProductPresentationSettings $settings,
        array $product,
        Language $language
    ) {
        $presentedProduct['images'] = $this->imageRetriever->getProductImages(
            $product,
            $language
        );

        if (!isset($presentedProduct['cover'])) {
            $presentedProduct['cover'] = $presentedProduct['images'][0];
        }

        return $presentedProduct;
    }

    private function addPriceInformation(
        array $presentedProduct,
        ProductPresentationSettings $settings,
        array $product,
        Language $language
    ) {
        $presentedProduct['has_discount'] = false;
        $presentedProduct['discount_type'] = null;
        $presentedProduct['discount_percentage'] = null;

        if ($settings->include_taxes) {
            $price = $regular_price = $product['price'];
        } else {
            $price = $regular_price = $product['price_tax_exc'];
        }

        if ($product['specific_prices']) {
            $presentedProduct['has_discount'] = true;
            $presentedProduct['discount_type'] = $product['specific_prices']['reduction_type'];
            // TODO: format according to locale preferences
            $presentedProduct['discount_percentage'] = -round(100 * $product['specific_prices']['reduction'])."%";
            $regular_price = $product['price_without_reduction'];
        }

        $presentedProduct['price'] = $this->pricePresenter->format(
            $this->pricePresenter->convertAmount($price)
        );

        $presentedProduct['regular_price'] = $this->pricePresenter->format(
            $this->pricePresenter->convertAmount($regular_price)
        );

        return $presentedProduct;
    }

    private function shouldShowAddToCartButton(
        ProductPresentationSettings $settings,
        array $product
    ) {
        $can_add_to_cart = $this->shouldShowPrice($settings, $product);

        if ($product['customizable'] == 2) {
            $can_add_to_cart = false;
        }

        if (!empty($product['customization_required'])) {
            $can_add_to_cart = false;
        }

        if ($product['quantity'] <= 0 && !$product['allow_oosp']) {
            $can_add_to_cart = false;
        }

        return $can_add_to_cart;
    }

    private function getAddToCartURL(array $product)
    {
        return $this->link->getPageLink(
            'cart',
            true,
            null,
            'add=1&id_product=' . $product['id_product'] . '&id_product_attribute=' . $product['id_product_attribute'],
            false
        );
    }

    private function getProductURL(array $product, Language $language)
    {
        return $this->link->getProductLink(
            $product['id_product'],
            null, null, null,
            $language->id,
            null,
            $product['id_product_attribute']
        );
    }

    private function addMainVariantsInformation(
        array $presentedProduct,
        array $product,
        Language $language
    ) {
        $colors = $this->productColorsRetriever->getColoredVariants($product['id_product']);

        if (!is_array($colors)) {
            return $presentedProduct;
        }

        $presentedProduct['main_variants'] = array_map(function (array $color) use ($language) {
            $color['add_to_cart_url']   = $this->getAddToCartURL($color);
            $color['url']               = $this->getProductURL($color, $language);
            $color['type']              = 'color';
            $color['html_color_code']   = $color['color'];
            unset($color['color']);
            unset($color['id_attribute']); // because what is a template supposed to do with it?

            return $color;
        }, $colors);

        return $presentedProduct;
    }

    public function present(
        ProductPresentationSettings $settings,
        array $product,
        Language $language
    ) {
        $presentedProduct = $product;

        $presentedProduct['show_price'] = $this->shouldShowPrice(
            $settings,
            $product
        );

        $presentedProduct = $this->fillImages(
            $presentedProduct,
            $settings,
            $product,
            $language
        );

        $presentedProduct['url'] = $this->getProductURL($product, $language);

        $presentedProduct = $this->addPriceInformation(
            $presentedProduct,
            $settings,
            $product,
            $language
        );

        if ($this->shouldShowAddToCartButton($settings, $product)) {
            $presentedProduct['add_to_cart_url'] = $this->getAddToCartURL($product);
        } else {
            $presentedProduct['add_to_cart_url'] = null;
        }

        $presentedProduct = $this->addMainVariantsInformation(
            $presentedProduct,
            $product,
            $language
        );

        return $presentedProduct;
    }

    public function presentForListing(
        ProductPresentationSettings $settings,
        array $product,
        Language $language
    ) {
        $presentedProduct = $this->present(
            $settings,
            $product,
            $language
        );

        if ($product['id_product_attribute'] != 0 && !$settings->allow_add_variant_to_cart_from_listing) {
            $presentedProduct['add_to_cart_url'] = null;
        }

        return $presentedProduct;
    }
}