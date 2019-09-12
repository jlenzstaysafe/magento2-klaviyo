<?php
namespace Klaviyo\Reclaim\Model;
use Klaviyo\Reclaim\Api\ReclaimInterface;
use \Magento\Framework\Exception\NotFoundException;


class Reclaim implements ReclaimInterface
{
    /**
     * Object Manager instance
     *
     * @var \Magento\Framework\ObjectManagerInterface
     */
    protected $_objectManager = null;
    protected $_subscriber;
    protected $_stockItemRepository;
    protected $subscriberCollection;
    public $response;

    const MAX_QUERY_DAYS = 10;
    const SUBSCRIBER_BATCH_SIZE = 500;

    public function __construct(
        \Magento\Framework\ObjectManagerInterface $objectManager, 
        \Magento\Quote\Model\QuoteFactory $quoteFactory,
        \Magento\Catalog\Model\ProductFactory $productFactory,
        \Magento\CatalogInventory\Api\StockStateInterface $stockItem,
        \Magento\CatalogInventory\Model\Stock\StockItemRepository $stockItemRepository,
        \Magento\Newsletter\Model\Subscriber $subscriber,
        \Magento\Newsletter\Model\ResourceModel\Subscriber\CollectionFactory $subscriberCollection,
        \Klaviyo\Reclaim\Helper\Data $klaviyoHelper
        )
    {
        $this->quoteFactory = $quoteFactory;
        $this->_productFactory = $productFactory;
        $this->_objectManager = $objectManager;
        $this->_stockItem = $stockItem;
        $this->_stockItemRepository = $stockItemRepository;
        $this->_subscriber= $subscriber;
        $this->_subscriberCollection = $subscriberCollection;
        $this->_klaviyoHelper = $klaviyoHelper;
    }

    /**
     * Returns extension version
     *
     * @api
     * @return string
     */
    public function reclaim(){
        return $this->_klaviyoHelper->getVersion();
    }

    /**
     * Returns all stores with extended descriptions
     *
     * @api
     * @return mixed
     */
    public function stores()
    {
        $object_manager = \Magento\Framework\App\ObjectManager::getInstance();
        $store_manager = $object_manager->get('\Magento\Store\Model\StoreManagerInterface');
        $stores = $store_manager->getStores();

        $hydrated_stores = array();
        foreach ($stores as $store)
        {
            $store_id = $store->getId();
            $store_website_id = $store->getWebsiteId();
            $store_name = $store->getName();
            $store_code = $store->getCode();
            $base_url = $store->getBaseUrl();
            $media_base_url = $store->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_MEDIA);

            array_push($hydrated_stores, array(
              'id' => $store_id,
              'website_id' => $store_website_id,
              'name' => $store_name,
              'code' => $store_code,
              'base_url' => $base_url,
              'media_base_url' => $media_base_url,
            ));
        }

        return $hydrated_stores;
    }
    public function product($quote_id, $item_id) {

        if (!$quote_id || !$item_id){
            throw new NotFoundException(__('quote id or item id not found'));
        }

        $quote = $this->quoteFactory->create()->load($quote_id);
        if (!$quote){
            throw new NotFoundException(__('quote not found'));
        }

        $item = $quote->getItemById($item_id);
        if (!$item){
            throw new NotFoundException(__('item not found'));
        }

        $product = $this->_objectManager->get('Magento\Catalog\Model\Product')->load($item->getProductId());

        $image_array = $this->_getImages($product);

        $response['$body'] = array(
            'id' => $item->getProductId(),
            'images' => $image_array
        );

        return $response;
    }

    /**
    * @return mixed
    */
    public function productVariantInventory($product_id, $store_id=0)
    {
        if (!$product_id){
            throw new NotFoundException(_('A product id is required'));
        }
        // if store_id is specificed, use it
        if ($store_id){
            $product = $this->_productFactory->create()->setStoreId($store_id)->load($product_id);    
        } else {
            $product = $this->_productFactory->create()->load($product_id);
        }

        if (!$product){
            throw new NotFoundException(_('A product with id '. $product_id .' was not found'));
        }

        $productId = $product->getId();

        $response = array(array(
            'id' => $productId,
            'sku' => $product->getSku(),
            'title' => $product->getName(),
            'price' => $product->getPrice(),
            'available' => true,
            'inventory_quantity' => $this->_stockItem->getStockQty($productId),
            'inventory_policy' => $this->_getStockItem($productId)
        ));
        // check to see if the product has variants, if it doesn't just return the product information
        try {
            $_children = $product->getTypeInstance()->getUsedProducts($product);
            // throws a fatal error, so catch it generically and return
        } catch (\Error $e) {
            return $response;
        }
        
        foreach ($_children as $child){
            $response['variants'][] = array(
                'id' => $child->getId(),
                'title' => $child->getName(),
                'sku' => $child->getSku(),
                'available' => $child->isAvailable(),
                'inventory_quantity' => $this->_stockItem->getStockQty($child->getId()),
                'inventory_policy' => $this->_getStockItem($child->getId()),
            );
        }

        return $response;

    }

    // handle inspector tasks to return products by id
    public function productinspector($start_id, $end_id){

        if (($end_id - $start_id) > 100){
            throw new NotFoundException(__('100 is the max batch'));
        } elseif (!$start_id || !$end_id) {
            throw new NotFoundException(__('provide a start and end filter'));
        }

        $response = array();
        foreach (range($start_id, $end_id) as $number) {
            $product = $this->_objectManager
                ->create('Magento\Catalog\Model\Product')
                ->load($number);

            if (!$product){
                continue;
            }
            $response[] = array(
                'id' => $product->getId(),
                'sku' => $product->getSku(),
                'type_id' => $product->getTypeId(),
                'price' => $product->getPrice()
            );
        }

        return $response;

    }

    public function getSubscribersCount()
    {
        $subscriberCount =$this->_subscriberCollection->create()->getSize();
        return $subscriberCount;
    }

    public function getSubscribersById($start_id, $end_id, $storeId=null)
    {
        if (!$start_id || !$end_id ){ 
            throw new NotFoundException(__('Please provide start_id and end_id'));
        }

        if ($start_id > $end_id){
            throw new NotFoundException(__('end_id should be larger than start_id'));
        }

        if (($end_id - $start_id) > self::SUBSCRIBER_BATCH_SIZE){
            throw new NotFoundException(__('Max batch size is 500'));
        }

        $storeIdFilter = $this->_storeFilter($storeId);

        $subscriberCollection =$this->_subscriberCollection->create()
            ->addFieldToFilter('subscriber_id', ['gteq' => (int)$start_id])
            ->addFieldToFilter('subscriber_id', ['lteq' => (int)$end_id])
            ->addFieldToFilter('store_id', [$storeIdFilter => $storeId]);

        $response = $this->_packageSubscribers($subscriberCollection);

        return $response;
    }

    public function getSubscribersByDateRange($start, $until, $storeId=null)
    {
        
        if (!$start || !$until ){ 
            throw new NotFoundException(__('Please provide start and until param'));
        }
        // start and until date formats
        // $until = '2019-04-25 18:00:00';
        // $start = '2019-04-25 00:00:00';

        $until_date = strtotime($until);
        $start_date = strtotime($start);
        if (!$until_date || !$start_date){
            throw new NotFoundException(__('Please use a valid date format YYYY-MM-DD HH:MM:SS'));
        }

        // don't want any big queries, we limit to 10 days
        $datediff = $until_date - $start_date;

        if (abs(round($datediff / (60 * 60 * 24))) > self::MAX_QUERY_DAYS){
            throw new NotFoundException(__('Cannot query more than 10 days'));
        }

        $storeIdFilter = $this->_storeFilter($storeId);
        
        $subscriberCollection =$this->_subscriberCollection->create()
            ->addFieldToFilter('change_status_at', ['gteq' => $start])
            ->addFieldToFilter('change_status_at', ['lteq' => $until])
            ->addFieldToFilter('store_id', [$storeIdFilter => $storeId]);

        $response = $this->_packageSubscribers($subscriberCollection);

        return $response;

    }
    public function _packageSubscribers($subscriberCollection)
    {
        $response = array();
        foreach ($subscriberCollection as $subscriber){
            $response[]= array(
                'email' => $subscriber->getEmail(),
                'subscribe_status' => $subscriber->getSubscriberStatus()
            );
        }
        return $response;
    }

    public function _storeFilter($storeId)
    {
        $storeIdFilter = 'eq';
        if (!$storeId){
            $storeIdFilter = 'nlike';
        }
        return $storeIdFilter;
    }

    public function _getImages($product)
    {
        $images = $product->getMediaGalleryImages();
        $image_array = array();
        
        foreach($images as $image) {
            $image_array[] = $this->handleMediaURL($image);
        }
        return $image_array;
    }
    public function handleMediaURL($image)
    {
        $custom_media_url = $this->_klaviyoHelper->getCustomMediaURL();
        if ($custom_media_url){
            return $custom_media_url . "/media/catalog/product" . $image->getFile();
        }
        return $image->getUrl();
    }
    public function _getStockItem($productId)
    {
        $stock = $this->_stockItemRepository->get($productId);
        return $stock->getManageStock();
    }
}