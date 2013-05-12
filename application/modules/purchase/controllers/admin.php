<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');

class Admin extends Admin_Controller {
	function  __construct() {
		parent::__construct();
	}
	
	public function index() {
		$filter = $this->input->post();
		
		$data = array('purchases' => $this->em->getRepository('purchase\models\Purchase')->getPurchases($filter),
					  'vendors' => $this->em->getRepository('vendor\models\Vendor')->getVendors(),
					  'statuses' => $this->em->getRepository('transaction_status\models\TransactionStatus')->getStatuses());
		
		$this->load->view('admin/header');
		$this->load->view('admin/index', $data);
		$this->load->view('admin/footer');
	}
	
	public function create() {
		$purchase = new purchase\models\Purchase;
		
		try {
			$this->_do($purchase);
			redirect('admin/purchase/edit/' . $purchase->getId());
		} catch(Exception $e) {
			$this->session->set_flashdata('message', array('type' => 'error', 'content' => 'Can not create this purchase.'));
		}
		
		redirect('admin/purchase/');
	}
	
	public function edit($id) {
		$purchase = $this->em->getRepository('purchase\models\Purchase')->findOneById($id);		
		
		if (!$purchase) {
			$this->session->set_flashdata('message', array('type' => 'error', 'content' => 'Can not find this purchase - #' . $id));
			redirect('admin/purchase');
		}
		
		// Get the selected vendor. 
		if ($this->input->post('vendor')) {
			$selected_vendor = $this->em->getRepository('vendor\models\Vendor')->findOneById($this->input->post('vendor'));
		} else {
			$selected_vendor = $purchase->getVendor();
		}		
		
		// Get all the current product ids, load it in the js localstore $selected_products, when this page is loaded
		$current_transaction_items = $purchase->getItems();
		$current_product_ids = array();
		if(!empty($current_transaction_items)) {
			foreach ($current_transaction_items as $current_item) {
				$current_product_ids[] = $current_item->getProduct()->getId();
			}
		}
		
		// Assign data to the template
		$data = array('purchase' 			=> $purchase,
					  'summary'				=> $purchase->getSummary(),
					  'selected_vendor' 	=> $selected_vendor,
					  'categories'  		=> $this->em->getRepository('category\models\Category')->getCategories(),
					  'vendors' 			=> $this->em->getRepository('vendor\models\Vendor')->getVendors(),
					  'statuses' 			=> $this->em->getRepository('transaction_status\models\TransactionStatus')->getStatuses(),
					  'product_frequency' 	=> $this->em->getRepository('purchase\models\Purchase')->getOrderFrequency($selected_vendor, $current_product_ids),
					  'product_pending' 	=> $this->em->getRepository('purchase\models\Purchase')->getSalePendingProd($current_product_ids));
		
		// Form validation
		if ($this->_purchase_validate() !== FALSE) {
			try {
				$this->_do($purchase);
				$data['message'] = array('type' => 'success', 'content' => 'Successfully updated.');
			} catch(Exception $e) {
				$this->session->set_flashdata('message', array('type' => 'error', 'content' => $e->getMessage()));
				redirect('admin/purchase/');
			}
		}
		
		if ($this->form_validation->error_array()) {
			$data = array('message' => array('type' => 'error', 'content' => $this->form_validation->error_array()));
		}
		
		$this->load->view('admin/header');
		$this->load->view('admin/edit', $data);
		$this->load->view('admin/footer');
	}
	
	public function delete() {
		$id = $this->uri->segment(4);
		
		try {
			$purchase = $this->em->getRepository('purchase\models\Purchase')->findOneById($id);
			
			$purchase->setDeletedAt(new DateTime);
			$this->em->persist($purchase);
			$this->em->flush();
			
			$this->session->set_flashdata('message', array('type' => 'success', 'content' => 'Successfully deleted.'));
		} catch (Exception $e) {
			$this->session->set_flashdata('message', array('type' => 'error', 'content' => $e->getMessage()));
		}
		
		redirect('admin/purchase');
	}
	
	private function _purchase_validate() {
		$purchase_validation_rule = array(
			'vendor' => array('field'=>'vendor',
							  'label'=>'Vendor',
							  'rules'=>''),
			'status' => array('field'=>'status',
							  'label'=>'Status',
							  'rules'=>''),
			'comment' => array('field'=>'comment',
							   'label'=>'Comment',
							   'rules'=>'xss_clean')
		);
		
		$this->form_validation->set_rules($purchase_validation_rule); 
		return $this->form_validation->run();
	}
	
	private function _do(purchase\models\Purchase $purchase) {
		// Set the Vendor
		$vendor = $this->em->getRepository('vendor\models\Vendor')->findOneById($this->input->post('vendor'));
		
		// Set default transaction status to "Draft"
		$post_status_id = $this->input->post('status');
		$status = $this->em->getRepository('transaction_status\models\TransactionStatus')->findOneById(!empty($post_status_id)? $post_status_id : 1);
		
		if ($this->input->post('edit')) {
			// Remove the current products
			$current_items = $purchase->getItems();
			if (!empty($current_items)) {
				foreach ($current_items as $current_item) {
					$purchase->removeItem($current_item);
					$this->em->remove($current_item);
				}
			}
		}
		
		// Set the products
		$products = $this->input->post('products');
		if (!empty($products)) {
			foreach ($products as $prod_id => $prod) {
				$product = $this->em->getRepository('product\models\Product')->findOneById($prod_id);
				
				$item = new transaction\models\TransactionItem;
				$item->setProduct($product);
				$item->setCost($product->getCost());
				$item->setSuggestedPrice($product->getSuggestedPrice());
				$item->setNoServicePrice($product->getNoServicePrice());
				$item->setFullServicePrice($product->getFullServicePrice());
				$item->setCNC($product->getCNC());
				
				$item->setTax($this->tax);
				
				$item->setQty($prod['qty']);
				$item->setComment($prod['comment']);
				
				$purchase->addItem($item);
			}
		}
		
		$summary = $purchase->getSummary();
		
		$purchase->setTotal($summary['total']);
		$purchase->setUser($this->current_user);
		$purchase->setVendor($vendor);
		$purchase->setStatus($status);
		$purchase->setComment($this->input->post('comment'));
		
		$this->em->persist($purchase);
		$this->em->flush();
	}
}