<?php

class ClassifiedsController extends AppController {
	
	public $name = 'Classifieds';	
	public $uses = array ('User', 'Category', 'Profession', 'DurationRate', 'Business', 
							'Classified', 'Province', 'Area',  'City', 'Availability',
							'Subprofession',  'CategoryProfession', 'Service', 'Reference',
							'DurationRate', 'LocationRate', 'MediaLimit', 'Photo', 'Video',
							'Tax', 'Discount', 'Gallery', 'Region', 'ClassifiedRegion', 'UserDiscount', 
							'PaymentTransaction', 'OverallDiscount', 'LearnMore');
	public $components = array('Auth', 'Upload', 'Email', 'Gmail', 'Paypal', 'Ssl', 'Ad');
	public $helpers = array('Ajax');
	
	private $duration_type = array('1' => 'days', '2' => 'weeks');
	private $duration_multiplier = array('1' => 1, '2' => 7);
	private $default_business_count = 3;
	private $default_category_id = 1;
	private $labour_id = 3;
	private $retail_id = 2;
	private $contractor_id = 1;
	
	private $country = "Canada";
	
	function beforeFilter() {
		parent::beforeFilter();
		
		$this->Auth->allow ( 'upload_image' );
		$this->Auth->allow ( 'getAreas' );
		$this->Auth->allow ( 'getProfessions' );
		$this->Auth->allow ( 'getProfessionsList' );
		$this->Auth->allow ( 'getDiscountAmount' );
		$this->Auth->allow ( 'getSubprofessionsListByProfessionId' );
		$this->Auth->allow ( 'search' );
		$this->Auth->allow ( 'disapprove_ad' );
		
		$this->disableCache();
		
		if(!$this->Auth->user() && $this->params['action'] == 'post_ad') {
			$this->flash('You need to be logged in or Registered with Canada Handymen in order to Post an Ad!');
			$this->Session->write('referer', '/' . $this->current_url());
			$this->Session->delete('flash_message');	
		}
	}
	
	function post_ad() {
		$this->set('title_for_layout', 'Post Ad');
		if(empty($this->data)) {
			$categories = $this->Category->find('all', array('fields' => array('Category.id', 'Category.name'), "order"=>"Category.name", 'recursive' => 0));
			$this->set('categories', $categories);
		} else {
			$this->Session->write('post_ad', $this->data);
			$this->Session->delete('gallery');
			$this->redirect ( array ('controller' => 'classifieds', 'action' => 'submit_ad' ) );
			// TODO validation (for category id and profession id)
		}
	}
	
	function submit_ad() {
		if(! $this->Session->check('post_ad')) {
			$this->redirect ( array ('controller' => 'classifieds', 'action' => 'post_ad' ) );
		}
		$now = date('Y-m-d H:i:s');
		$post_ad = $this->Session->read('post_ad');
		$provinces = $this->Province->find('list', array("fields"=>array("Province.id", "Province.name"), 'conditions' => array('Province.disapproved' => 0))); 
		$this->set("provinces", $provinces);
		$this->set("profession", $this->Profession->find('first', array("conditions"=>array("Profession.id"=>$post_ad['Classified']['profession_id']), 'recursive' => -1)));
		$this->set("learn_more", $this->LearnMore->find('list', array("fields"=>array("LearnMore.type", "LearnMore.text"), "conditions"=>array("LearnMore.category_id"=>$post_ad['Business']['category_id'], "LearnMore.published" => 1))));
		
		if($post_ad['Business']['category_id'] == $this->contractor_id || $post_ad['Business']['category_id'] == $this->retail_id) {
			$overall_discount = $this->OverallDiscount->find('first', array("conditions" => array("OverallDiscount.start_date <=" => $now, "OverallDiscount.end_date >=" => $now)));
			$overall_discount_amount = $overall_discount ? $overall_discount['OverallDiscount']['amount'] : 0;
			$this->set('overall_discount', $overall_discount_amount);
			$tax = $this->Tax->find('first');
			$tax_amount = $tax['Tax']['amount'] ? $tax['Tax']['amount'] :0 ; 
			$this->set('tax', $tax_amount);
		
			$this->set('services', $this->Service->find('list', array('fields' => array('Service.name'))));
			$this->set('duration_rates', $this->DurationRate->find('all'));
			$this->set('media_limit', $this->MediaLimit->find('list', array('fields' => array('MediaLimit.limit', 'MediaLimit.rate','MediaLimit.type'))));
			$free_ads_count = $this->Classified->find('count', array('conditions' => array('Business.category_id' => $post_ad['Business']['category_id'], 'Classified.subtotal' => 0, 'Classified.user_id' => $this->Auth->user('id'))));
			$this->set('free_ads_count',$free_ads_count);
		} else {
			$this->set('availabilities', $this->Availability->find('list', array('fields' => array('Availability.name'))));
			$this->Profession->ProfessionSubprofession->bindModel(array('belongsTo'=>array('Subprofession')));
			$this->set('subprofessions',$this->Subprofession->ProfessionSubprofession->find('all' , array('conditions' => array('ProfessionSubprofession.profession_id' => $post_ad['Classified']['profession_id']),
								'fields' => array('Subprofession.id', 'Subprofession.name'))));
		}
		if(empty($this->data)) {
			switch($post_ad['Business']['category_id']) {
				case $this->contractor_id:
					
					
					$this->data = $this->Business->find('first', 
														array('conditions' => array('Business.category_id' => $post_ad['Business']['category_id'],
																					'Business.user_id' => $this->Auth->user('id')),
																'recursive' => 0));
														
					$this->render('contractor_ad');
					
					break;
				case $this->retail_id:
					
					$this->data = $this->Business->find('first', 
														array('conditions' => array('Business.category_id' => $post_ad['Business']['category_id'],
																					'Business.user_id' => $this->Auth->user('id')),
																'recursive' => 0));
					$areas = $this->Region->find('list', array("conditions"=>array("Region.province_id"=>key($provinces), "Region.area_id" => null), 'recursive' => -1));
					$cities = $this->Region->find('list', array("conditions"=>array("Region.area_id" => key($areas)), 'recursive' => -1));
					$this->set('areas', $areas);
					$this->set('cities', $cities);
					$this->render('retailer_ad');
					break;
				case $this->labour_id:
					$this->data = $this->Business->find('first', 
														array('conditions' => array('Business.category_id' => $post_ad['Business']['category_id'],
																					'Business.user_id' => $this->Auth->user('id')),
																'recursive' => 0));	
					$this->render('labour_ad');
					break;
			}
		} else { // user submitted ad
			$category_id = $post_ad['Business']['category_id'];
			$subtotal = 0;
			
			$this->data['Business']['category_id'] = $category_id;
			$this->data['Business']['user_id'] = $this->Auth->user('id');
			$this->data['Classified']['user_id'] = $this->Auth->user('id');
			$this->data['Classified']['profession_id'] = $post_ad['Classified']['profession_id'];
			$area_ids = $this->data['Classified']['area_ids'];
			$city_ids = $this->data['Classified']['city_ids'];
			$city_area_ids = $this->data['Classified']['city_area_ids'];
			if($area_ids == "") {
				$area_ids = array();
			} else {
				$area_ids = explode(',', $area_ids);	
			}
			
			if($city_ids == "") {
				$city_ids = array();	
			} else {
				$city_ids = explode(',', $city_ids);
			}
			
			if($city_area_ids == "") {
				$city_area_ids = array();
			} else {
				$city_area_ids = explode(',', $city_area_ids);
			}
			
			switch ($category_id) {
				case $this->contractor_id:
				case $this->retail_id:
					//----- start subtotal calcultaion ----//
					if(isset($this->data['Classified']['location_rate_id'])) {
						$this->DurationRate->bindModel(array('hasMany' => array('LocationRate' => array('conditions' => array('LocationRate.id' => $this->data['Classified']['location_rate_id'])))));
						$duration_limitation = $this->DurationRate->find('first', array('conditions' => array('DurationRate.id' => $this->data['Classified']['duration_rate_id'])));
						$subtotal += $duration_limitation['LocationRate'][0]['rate'];
					} else {
						$duration_limitation = $this->DurationRate->find('first', array('conditions' => array('DurationRate.id' => $this->data['Classified']['duration_rate_id'])));
					}
					
					$videoValidates = true;
					if($this->data['Classified']['photo'] || $this->data['Classified']['video']) {

						$media_limitation = $this->MediaLimit->find('list', array('fields' => array('MediaLimit.limit', 'MediaLimit.rate','MediaLimit.type')));
						if($this->data['Classified']['photo']) {
							$photo_limit = $media_limitation['image'];
							foreach ($photo_limit as $key => $value) {
								$subtotal += $photo_limit[$key];
							}
							$photo_array = array();
							foreach ($this->data['PhotoHelper'] as $key => $value) {
								if($key == 'desc') {
									continue;
								}
								if(isset($value['helper']) && $value['helper'] != "") {
									$photo_array[] = array('name' => $value['helper'], 'description' => $value['description']);
								}
							}
							if(count($photo_array) > 0) {
								$this->Session->write('Photo', $photo_array);
							}
						}
						if($this->data['Classified']['video']) {
							$video_limit = $media_limitation['video'];
							foreach ($video_limit as $key => $value) {
								$subtotal += $video_limit[$key];
							}
							
							if($this->data['Video']) {
								$video_arr = array();
								$videoValidates = $this->Video->saveAll($this->data['Video'], array('validate' => 'only'));
								foreach ($this->data['Video'] as $key => $value) {
									if($value['url'] == "") {
										continue;
									}
									$video_arr [] = $value;
								}
								if(count($video_arr) > 0) {
									$this->Session->write('Video', $video_arr);
								}
							}
						}
					}
					if($this->data['Business']['service'] != "") {
						$this->data['Classified']['service_ids'] = implode(',' ,$this->data['Business']['service']);
					}
					if($this->data['Classified']['add_description']) {
						$subtotal += $duration_limitation['DurationRate']['description_rate'];
					}
					
					if($this->data['Classified']['custom_ad']) {
						$subtotal += $duration_limitation['DurationRate']['customad_rate'];
					}
					
					if(! ($this->data['Classified']['add_description'] || $this->data['Classified']['custom_ad'])) {
						$this->data['Classified']['ad_text'] = "";
					}
					if($this->data['Classified']['top_ad']) {
						$subtotal += $duration_limitation['DurationRate']['topad_rate'];
					}
					
					//calculate subtotal discount and taxes 
					$code = $this->data['Discount']['amount'];
					$discount = $this->_getDiscountAmountByCode($code);
					$discount_amount = $discount['amount'];
					
					$max_discount = $overall_discount_amount > $discount_amount ? $overall_discount_amount : $discount_amount;
					
					// apply discount
					$subtotal -= $subtotal * $max_discount / 100;
					 
					// apply tax
					$subtotal += $subtotal * $tax_amount / 100;
					
					if($subtotal > 0) {
						$subtotal = number_format($subtotal, 2);
					}
					$this->data['Classified']['subtotal'] = $subtotal;
					//if this is not a custom ad , then it wil be published, we have to set date for publih
					if(! $this->data['Classified']['custom_ad']) {
						// set published date
						$this->data['Classified']['published_date'] = date ( 'Y-m-d H:i:s' );
						$this->data['Classified']['expire_date'] = date('Y-m-d', strtotime('+ ' . $duration_limitation['DurationRate']['duration'] . ' month'));
					} else {
						// its a custom ad, set the approved tag to 0, admin should upload a custom view, 
						//then set as published
						$this->data['Classified']['approved'] = 0;
					}
					// token will be user in a url that will disapprove the ad 
					$token = md5(date('mdY').rand(4000000,4999999));
					$this->data['Classified']['hash'] = $token;
					
					// set acction type later to access in payment
					$this->data['Classified']['action'] = 'save';
					$this->Session->write('submit_ad', $this->data);
					//----- end subtotal calcultaion ----//
					
					$this->Session->write('subtotal', $subtotal);
					
					if($this->data['Business']['action'] == 'preview') {
						$this->data['Business']['accept_terms'] = 1;
					}
					$this->Business->set( $this->data );
					$this->Classified->set($this->data);
					$businessValidates = $this->Business->validates();
					
					$classifiedValidates = $this->Classified->validates();
					if($businessValidates && $classifiedValidates && $videoValidates && $this->data['Business']['action'] == 'save'){
						// prepare region for further saving
						$regions_arr = array();
						foreach ($city_ids as $key => $value) {
							$reg = null;
							$reg['area_id'] = $city_area_ids[$key];
							$reg['region_id'] = $value;
							$regions_arr[] = $reg;
						}
						
						foreach ($area_ids as $key => $value) {
							$reg = null;
							$reg['region_id'] = $value;
							$regions_arr[] = $reg;
						}
						
						if(count($regions_arr)) {
							$this->Session->write('Region', $regions_arr);
						}
						
						// if the user requested for a custom ad
						if($this->data['Classified']['custom_ad']) {
							// then send an email about custom ad request
							$email = $this->data['Business']['email'];
							$this->Email->smtpOptions = array(
							   'port'=>'465',
							   'timeout'=>'30',
							   'host' => 'ssl://smtp.gmail.com',
							   'username'=> Configure::read('SMPT.email'),
							   'password'=> Configure::read('SMPT.password')
							);
							 
							$this->Email->delivery = 'smtp';
							$this->Email->from = $email . ' <' . $email . '>';
							$this->Email->to = Configure::read('Admin.email') . ' <' . Configure::read('Admin.email') . '>';
							 
							$this->Email->template = 'default';
							$this->Email->sendAs = 'both';
							$this->Email->subject = 'Custom Ad Request';
							$this->Email->send($this->data['Classified']['ad_text']);
						}
						
						if($subtotal === 0) { //then save the ad
							$classified_id = $this->Ad->save();
							// send email to admin about new posted ad
							$this->_sendAdUploadMessageToAdmin($classified_id);
							
							
							$this->Email->smtpOptions = array(
								'port'=>'465', 
								'timeout'=>'30',
								'host' => 'ssl://smtp.gmail.com',
								'username'=> Configure::read('SMPT.email'),
								'password'=> Configure::read('SMPT.password')
							);
							
							$this->Email->delivery = 'smtp';
							$this->Email->from = Configure::read('SMPT.name') . ' <' . Configure::read('SMPT.email') . '>';
							$this->Email->subject = 'Congratulations';
							
							$this->Email->template = 'ad_message';
						    $this->Email->sendAs = 'both';
							
							$this->Email->to = $this->data['Business']['email'] . ' <' . $this->data['Business']['email'] . '>';
							$this->set('username', $this->Auth->user('username'));
							$this->Email->send();
							
							$this->flash('Congrats! Your ad is submitted!', 'status');
							$this->redirect ( array ('controller' => 'pages', 'action' => 'home' ) );
						} else {
							$this->redirect ( array ('controller' => 'classifieds', 'action' => 'payment' ) );
						}
					} else { // there were some validation errors
						if($this->Session->check('submit_ad')) {
							if($this->data['Business']['action'] == 'preview' && $businessValidates && $classifiedValidates && $videoValidates ) {
								$this->Profession->unBindModel(array('hasAndBelongsToMany' => array('Subprofession', 'Category')));
								$profession = $this->Profession->find('first', array('conditions' => array('Profession.id' => $post_ad['Classified']['profession_id'], 'Profession.disapproved' => 0)));
								$this->set('show_preview_popup', 1);	
								$this->set('profession_name', $profession['Profession']['name']);
								if($category_id == $this->retail_id) {
									$map_address = "";
									$address = "";
									if($this->data['Business']['street_number'] && $this->data['Business']['street_name']) {
										$map_address .= $this->data['Business']['street_number'] . "+" . $this->data['Business']['street_name'];
										$address = $this->data['Business']['street_number'] . " " . $this->data['Business']['street_name'];
									}
									
									if($this->data['Business']['city'] && $this->data['Business']['city']) {
										if($map_address != "") {
											$map_address .= '+';
										}
										
										if($address != "") {
											$address .= ', ';
										}
										
										$map_address .= $this->data['Business']['city'];
										$address .= $this->data['Business']['city'];
									}
									
									/*if(isset($classified['Region']) && !empty($classified['Region']) && $classified['Region'][0]['Province']['name'] && $classified['Region'][0]['Province']['name']) {
										if($map_address != "") {
											$map_address .= '+';
										}
										
										if($address != "") {
											$address .= ', ';
										}
										
										$map_address .= $classified['Region'][0]['Province']['name'];
										$address .= $classified['Region'][0]['Province']['name'];
									}
									
									if($address != "") {
										$address .= ', ';
									}*/
									$address .= $this->country;
									
									if($this->data['Business']['postal_code'] && $this->data['Business']['postal_code']) {
										if($map_address != "") {
											$map_address .= '+';
										}
										
										if($address != "") {
											$address .= ', ';
										}
										
										$map_address .= $this->data['Business']['postal_code'];
										$address .= $this->data['Business']['postal_code'];
									}
									
									if($map_address != "") {
										$map_address .= '+';
									}
									
									$map_address .= $this->country; 
									
									$this->set('map_address', $map_address);
									$this->set('address', $address);
								}
							}
							
							$this->log($this->Business->validationErrors, 'submit_add');
							$this->log($this->Classified->validationErrors, 'submit_add');
							
							
							$this->data = $this->Session->read('submit_ad');

							if($category_id == $this->retail_id) {
								$areas = $this->Region->find('list', array("conditions"=>array("Region.province_id"=>$this->data['Classified']['province_id'], "Region.area_id" => null), 'recursive' => -1));
								$cities = $this->Region->find('list', array("conditions"=>array("Region.area_id" => $this->data['Classified']['area_id']), 'recursive' => -1));
								$this->set('cities', $cities);
								$this->set('areas', $areas);
							}
							
							$region_ids = array_merge($area_ids, $city_ids);
							if(count($region_ids)) {
								$regions = $this->Region->find('all', array( 'conditions' => array('Region.id' => $region_ids, 'Region.disapproved' => 0), 'recursive' => -1) );
								foreach ($regions as $region) {
									$this->data['Region'][] = $region['Region'];
								}
							} 
//							unset($this->data['Business']['accept_terms']);
							if($category_id == $this->contractor_id) {
								$this->render('contractor_ad');
							} else if($category_id == $this->retail_id) {
								$this->render('retailer_ad');
							} 
						}
					}
					
					break;
				
				case $this->labour_id:
					$this->Session->write('subtotal', 0);
					// availability
					if($this->data['Business']['availability']) {
						$this->data['Classified']['availability_ids'] = implode(',' ,$this->data['Business']['availability']);
					}
					
					//photo
					$photo_array = array();
					foreach ($this->data['PhotoHelper'] as $key => $value) {
						if($key == 'desc') {
							continue;
						}
						if(isset($value['helper']) && $value['helper'] != "") {
							$photo_array[] = array('name' => $value['helper'], 'description' => $value['description']);
						}
					}
					if(count($photo_array) > 0) {
						$this->Session->write('Photo', $photo_array);
					}
					
					// reference
					$referenceValidates = true;
					if($this->data['Reference']) {
							$reference_arr = array();
							
							foreach ($this->data['Reference'] as $key => $value) {
								if($value['email'] == "") {
									continue;
								}
								//$value['user_id'] = $this->Auth->user('id');
								$value['hash'] = String::uuid();
								$value['user_email'] = $this->Auth->user('email');
								$value['business_email'] = $this->data['Business']['email'];
								$reference_arr [] = $value;
							}
							
							if(count($reference_arr)) {
								$referenceValidates = $this->Reference->saveAll($reference_arr,array('validate' => 'only'));
								$this->Session->write('Reference', $reference_arr);
							}
						}
					
					// subprofession
					if(isset($this->data['Classified']['subprofession_ids']) && $this->data['Classified']['subprofession_ids'] != "") {
						$this->data['Classified']['subprofession_ids'] = implode(',' ,$this->data['Classified']['subprofession_ids']);
					}
					
					// set published date
					$this->data['Classified']['published_date'] = date ( 'Y-m-d H:i:s' );
					$this->Session->write('submit_ad', $this->data);
					if($this->data['Business']['action'] == 'preview') {
						$this->data['Business']['accept_terms'] = 1;
					}
					$this->Business->set( $this->data );
					$this->Classified->set($this->data);
					$businessValidates = $this->Business->validates();
					$classifiedValidates = $this->Classified->validates();
					
					if($businessValidates && $classifiedValidates && $referenceValidates && $this->data['Business']['action'] == 'save'){
						$regions_arr = array();
						foreach ($city_ids as $key => $value) {
							$reg = null;
							$reg['area_id'] = $city_area_ids[$key];
							$reg['region_id'] = $value;
							$regions_arr[] = $reg;
						}
						
						foreach ($area_ids as $key => $value) {
							$reg = null;
							$reg['region_id'] = $value;
							$regions_arr[] = $reg;
						}
						
						if(count($regions_arr)) {
							$this->Session->write('Region', $regions_arr);
						}
						// TODO send email to admin
						$classified_id = $this->Ad->save();
						$this->_sendAdUploadMessageToAdmin($classified_id);

						$this->Email->smtpOptions = array(
							'port'=>'465', 
							'timeout'=>'30',
							'host' => 'ssl://smtp.gmail.com',
							'username'=> Configure::read('SMPT.email'),
							'password'=> Configure::read('SMPT.password')
						);
						
						$this->Email->delivery = 'smtp';
						$this->Email->from = Configure::read('SMPT.name') . ' <' . Configure::read('SMPT.email') . '>';
						$this->Email->subject = 'Reference Request';
						
						$this->Email->template = 'reference_message';
					    //Send as 'html', 'text' or 'both' (default is 'text')
					    $this->Email->sendAs = 'both';
						
						foreach($reference_arr as $reference) {
							$this->Email->to = $reference['email'] . ' <' . $reference['email'] . '>';
						    $this->set('name', $this->Auth->user('name'));
						    $this->set('hash', $reference['hash']);
							
							$this->Email->send();	
						}
						$this->flash('Congrats! Your ad is submitted!', 'status');
						$this->redirect ( array ('controller' => 'pages', 'action' => 'home' ) );
					} else { // there were some validation errors
						if($this->Session->check('submit_ad')) {
							
							if($this->data['Business']['action'] == 'preview' && $businessValidates && $classifiedValidates && $referenceValidates ) {
								$this->Profession->unBindModel(array('hasAndBelongsToMany' => array('Subprofession', 'Category')));
								$profession = $this->Profession->find('first', array('conditions' => array('Profession.id' => $post_ad['Classified']['profession_id'], 'Profession.disapproved' => 0)));
								$this->set('show_preview_popup', 1);	
								$this->set('profession_name', $profession['Profession']['name']);
							}
							$this->data = $this->Session->read('submit_ad');
							
							$this->log($this->Business->validationErrors, 'submit_add');
							$this->log($this->Classified->validationErrors, 'submit_add');
							
							$region_ids = array_merge($area_ids, $city_ids);
							if(count($region_ids)) {
								$regions = $this->Region->find('all', array( 'conditions' => array('Region.id' => $region_ids, 'Region.disapproved' => 0), 'recursive' => -1) );
								foreach ($regions as $region) {
									$this->data['Region'][] = $region['Region'];
								}
							} 
//							unset($this->data['Business']['accept_terms']);
							$this->render('labour_ad');
						}
					}
					
					break;
				
			}
			
			//----- end switch($category_id) -----//
		} 
	}
	
	/**
	 * Returns the count of posted classifieds for particular profession
	 * If there is none the view part will alow posting, otherwise will reject 
	 *
	 */
	function getClassified() {
		if($this->RequestHandler->isAjax()) {
			$this->autoRender = $this->layout = false;
			$profession_count = $this->Classified->find('count', array('conditions' => array(
													'Classified.profession_id' => $this->params['form']['profession_id'],
													'Classified.user_id' => $this->Auth->user('id')), 'recursive' => 0));
			echo $profession_count;
			exit();
		}
	}
	
	function getProfessionsAndSubprofessions($category_id){
		$profession_ids = $this->CategoryProfession->find('list', array('conditions' => array('CategoryProfession.category_id' => $category_id),'fields' => array('CategoryProfession.profession_id')));
		$professions = $this->Subprofession->Profession->find('all', array('conditions' => array('Profession.id' => $profession_ids, 'Profession.disapproved' => 0), 'order' => array('Profession.name ASC')));
		return $professions;
	}
	
	function getAreas() {
		if($this->RequestHandler->isAjax()) {
			$this->autoRender = $this->layout = false;
			//$province = $this->Province->find('first', array('conditions' => array('Province.key' =>$this->params['form']['province_key'], 'Province.disapproved' => 0)));
//			$areas = $this->Area->findAllByProvinceId($province['Province']['id'], array('restrict'=>array()));
			
			$this->Region->unBindModel(array('hasAndBelongsToMany' => array('Classified')));
			$areas = $this->Region->find('all', array('conditions' => array('Region.province_id' => $this->params['form']['province_id'], 'Region.area_id' => null, 'Region.disapproved' => 0), 'recursive' => 1));
			
			echo json_encode($areas);
			exit;  
		}
	}
	
	
	function getCitiesByArea() {
		if($this->RequestHandler->isAjax()) {
			$this->autoRender = $this->layout = false;
			
			$this->Region->unBindModel(array('hasAndBelongsToMany' => array('Classified')));
			$cities = $this->Region->find('all', array('conditions' => array('Region.area_id' => $this->params['form']['area_id'], 'Region.disapproved' => 0), 'recursive' => -1));
			
			echo json_encode($cities);
			exit;  
		}
	}
	
	
	function getProfessions() {
		if($this->RequestHandler->isAjax()) {
			$this->autoRender = $this->layout = false;
			$professions = $this->Profession->Category->find('all', array('conditions'=>array('Category.id' => $this->params['form']['category_id'], 'Profession.disapproved' => 0),
																		   "order"=>"Category.name"));
			echo json_encode($professions);
			exit;  
		}
	}
	
	/**
	 * Function to respond ajax call
	 * Returns Professsions with appropriate subProfessions, by the passed category id
	 *
	 */
	function getProfessionsList() {
		if($this->RequestHandler->isAjax()) {
			$this->autoRender = $this->layout = false;
			$professions = $this->getProfessionsAndSubprofessions($this->params['form']['category_id']);
			echo json_encode($professions);
			exit();
		}
	}
	
	/**
	 * Gets and returns subprofessions by the given profession id
	 */
	function getSubprofessionsListByProfessionId() {
		if($this->RequestHandler->isAjax()) {
			$this->Profession->ProfessionSubprofession->bindModel(array('belongsTo'=>array('Subprofession')));
			$subprofessions = $this->Subprofession->ProfessionSubprofession->find('all' , array('conditions' => array('ProfessionSubprofession.profession_id' => $this->params['form']['profession_id']),
								'fields' => array('Subprofession.id', 'Subprofession.name')));
			echo json_encode($subprofessions);
			exit();
		}
	}
	
	/**
	 * Function to respond ajax call.
	 * Chechks if there exists a promotion with the user's entered code and 
	 * if the user already has used that code to post another ad.
	 * Returns discount amount on behalf of users' enterd code
	 *
	 */
	function getDiscountAmount() {
		if($this->RequestHandler->isAjax()) {
			$this->autoRender = $this->layout = false;
			$code = $this->params['form']['key'];
			$result = $this->_getDiscountAmountByCode($code);
			echo json_encode($result);
			exit();
		}
	}
	
	/**
	 *  A private function to get discount amount by discount code
	 */
	function _getDiscountAmountByCode($code) {
		$discount = $this->Discount->find('first', array('conditions' => array('Discount.code LIKE BINARY' => trim($code))));
		$result['error'] = "";
		$result['amount'] = 0;
		if($discount) {
			$start_date = strtotime($discount['Discount']['start_date']);
			$end_date = strtotime($discount['Discount']['end_date']);
			$now = strtotime(date('Y-m-d H:i:s'));
			// check if the discount is expired
			if($now >= $start_date && $now <= $end_date) {
				$user_discount = $this->UserDiscount->find('count', array('conditions' => array('user_id' => $this->Auth->user('id'), 'discount_id' => $discount['Discount']['id'])));
				if(! $user_discount) {
					$result['amount'] = $discount['Discount']['amount'];
				} else {
					$result['error'] = "Sorry, you have already applied this code.";
				}
			} else {
				$result['error'] = "Sorry, the code has already been expired.";
			}
		} else {
			$result['error'] = "Please, enter a valid discount code.";
		}		
		
		return $result;
	}
	
	function upload_image($width = null, $height = null) {
		if (!empty($this->data)) {
			// allow only images
			$allowed = array('image/jpeg', 'image/pjpeg', 'image/png', 'image/gif', 'image/tiff', 'image/x-tiff'); 
			$errors = "";
			$result = "";
			$destination = WWW_ROOT . 'img' . DS . 'uploads' . DS;
			$file = $this->data['Classified']['filedata'];
			$size = getimagesize($file['tmp_name']);
			$originalWidth = $size[0];
			$originalHeight = $size[1];
			if($width && $height && is_numeric($width) && is_numeric($height)){
				$width = intval($width);
				$height = intval($height);
				$type = "resizecrop";
				if($originalWidth < $width || $originalHeight < $height) {
					$errors = "Uploaded image must be at list $width x $height in size.";
					echo json_encode(array('error' => $errors));
					exit();
				}
			} else {
//				$width = 400;
//				$height = 300;
				$width = $originalWidth;
				$height = $originalHeight;
				$type = "resizemin";
			}
			$result = $this->Upload->upload($file, $destination, null, array('type' => $type, 'size' => array($width, $height), 'output' => 'jpg'), $allowed);
			if ($this->Upload->result){
				$result = $this->Upload->result;
			} else {
				$errors = $this->Upload->errors;
				if(is_array($errors)){
					$errors = implode("<br />",$errors); 
				}
			}
			echo json_encode(array('error' => $errors, 'msg' => $result));
			exit();
		}
	}

	function upload_resume() {
		if (!empty($this->data)) {
			// allow only docs and pdfs
			$allowed = array('application/pdf', 'application/x-pdf', 'application/acrobat', 'text/pdf', 'text/x-pdf',
								'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
			$errors = "";
			$result = "";
			$destination = WWW_ROOT . 'resumes' . DS;
			$file = $this->data['Classified']['uploadResume'];
			$result = $this->Upload->upload($file, $destination, null, null, $allowed);
			if($this->Upload->result) {
				$result = $this->Upload->result;
			} else {
				$errors = $this->Upload->errors;
				if(is_array($errors)){
					$errors = implode("<br />",$errors); 
				}
			}
			echo json_encode(array('error' => $errors, 'msg' => $result));
			exit();
		}
	}
	function remove_image() {
		if($this->RequestHandler->isAjax()) {
			$this->autoRender = $this->layout = false;
			$image_name = $this->params['form']['image_name'];
			//unlink here
			$file = new File(WWW_ROOT ."/img/uploads/$image_name");
			if($file->delete()){
			   echo "file deleted successfully";
			}else{
			   echo "file failed to be delete";
			}
			exit();
		}
	}
	
	function remove_resume() {
		if($this->RequestHandler->isAjax()) {
			$this->autoRender = $this->layout = false;
			$file_name = $this->params['form']['file_name'];
			//unlink here
			$file = new File(WWW_ROOT ."/resumes/$file_name");
			if($file->delete()){
			   echo "file deleted successfully";
			}else{
			   echo "file failed to be delete";
			}
			exit();
		}
	}
		
	function _get($var) { 
	    return isset($this->params['url'][$var])? $this->params['url'][$var]: null; 
	} 
	
	function payment() {
		$subtotal = $this->Session->read('subtotal');
		
		$paymentInfo['Order']['theTotal'] = $subtotal; 
        $paymentInfo['Order']['returnUrl'] = Router::url("/classifieds/confirm/", true); 
        $paymentInfo['Order']['cancelUrl'] = Router::url("", true);
        
        // call paypal 
        $result = $this->Paypal->processPayment($paymentInfo, "SetExpressCheckout");

        $ack = strtoupper($result["ACK"]); 
        //Detect Errors 
        if($ack!="SUCCESS") {
            $error = $result['L_LONGMESSAGE0'];
            
            $data = $this->Session->read('submit_ad');
            $this->PaymentTransaction->create();
			$this->PaymentTransaction->set('user_id', $data['Business']['user_id']);
			$this->PaymentTransaction->set('paid_amount', $data['Classified']['subtotal']);
			$this->PaymentTransaction->set('discount_amount', $data['Discount']['amount'] ? $data['Discount']['amount'] : 0);
			$this->PaymentTransaction->set('message', $result["L_SHORTMESSAGE0"]);
			$this->PaymentTransaction->set('long_message', $result["L_LONGMESSAGE0"]);
			$this->PaymentTransaction->save();
			
			$this->flash("SetExpressCheckout failed", 'status');
            $this->redirect ( array ('controller' => 'pages', 'action' => 'home' ) );
        } else { 
            // send user to paypal 
            $token = urldecode($result["TOKEN"]); 
            $payPalURL = PAYPAL_URL . $token; 
            $this->redirect($payPalURL); 
        } 
	}
	function confirm() {
		//TODO Handle errors and show them
		$subtotal = $this->Session->read('subtotal');
		
        $result = $this->Paypal->processPayment($this->_get('token'),"GetExpressCheckoutDetails");

        $result['PAYERID'] = $this->_get('PayerID'); 
        $result['TOKEN'] = $this->_get('token'); 
        $result['ORDERTOTAL'] = $subtotal; 
        $ack = strtoupper($result["ACK"]);
        if($ack != "SUCCESS"){ 
            $error = $result['L_LONGMESSAGE0']; 
            $this->set('error', $error);
            
            $data = $this->Session->read('submit_ad');
            $this->PaymentTransaction->create();
			$this->PaymentTransaction->set('user_id', $data['Business']['user_id']);
			$this->PaymentTransaction->set('paid_amount', $data['Classified']['subtotal']);
			$this->PaymentTransaction->set('discount_amount', $data['Discount']['amount'] ? $data['Discount']['amount'] : 0);
			$this->PaymentTransaction->set('message', $result["L_SHORTMESSAGE0"]);
			$this->PaymentTransaction->set('long_message', $result["L_LONGMESSAGE0"]);
			$this->PaymentTransaction->save();
            
            $this->flash("GetExpressCheckoutDetails failed", 'status');
            $this->redirect ( array ('controller' => 'pages', 'action' => 'home' ) );
        } else { 
            $info = $this->Session->read('submit_ad');
	    	$result['orderTotal'] = $subtotal; 
	        $result = $this->Paypal->processPayment($result, "DoExpressCheckoutPayment");

	        //Detect errors 
	        $ack = strtoupper($result["ACK"]); 
	        if($ack != "SUCCESS") {
	            $error = $result['L_LONGMESSAGE0']; 
	            $this->set('error',$error);
	            
	            $data = $this->Session->read('submit_ad');
	            $this->PaymentTransaction->create();
				$this->PaymentTransaction->set('user_id', $data['Business']['user_id']);
				$this->PaymentTransaction->set('paid_amount', $data['Classified']['subtotal']);
				$this->PaymentTransaction->set('discount_amount', $data['Discount']['amount'] ? $data['Discount']['amount'] : 0);
				$this->PaymentTransaction->set('message', $result["L_SHORTMESSAGE0"]);
				$this->PaymentTransaction->set('long_message', $result["L_LONGMESSAGE0"]);
				$this->PaymentTransaction->save();
				
	            $this->flash("DoExpressCheckoutPayment failed");
	            $this->redirect ( array ('controller' => 'pages', 'action' => 'home' ) ); 
	        } else {
	        	$data = $this->Session->read('submit_ad');
	        	if($data['Classified']['action'] == 'save') {
					$classified_id = $this->Ad->save();
	        	} else {
	        		$classified_id = $this->Ad->update();
	        	}
				
	        	$this->_sendAdUploadMessageToAdmin($classified_id);
				$this->PaymentTransaction->create();
				$this->PaymentTransaction->set('user_id', $data['Business']['user_id']);
				$this->PaymentTransaction->set('classified_id', $classified_id);
				$this->PaymentTransaction->set('transaction_id', $result["PAYMENTINFO_0_TRANSACTIONID"]);
				$this->PaymentTransaction->set('paid_amount', $data['Classified']['subtotal']);
				$this->PaymentTransaction->set('discount_amount', $data['Discount']['amount'] ? $data['Discount']['amount'] : 0);
				$this->PaymentTransaction->set('message', $ack);
				$this->PaymentTransaction->save();				
				
				$this->Email->smtpOptions = array(
					'port'=>'465', 
					'timeout'=>'30',
					'host' => 'ssl://smtp.gmail.com',
					'username'=> Configure::read('SMPT.email'),
					'password'=> Configure::read('SMPT.password')
				);
				
				$this->Email->delivery = 'smtp';
				$this->Email->from = Configure::read('SMPT.name') . ' <' . Configure::read('SMPT.email') . '>';
				$this->Email->subject = 'Congratulations';
				
				$this->Email->template = 'ad_message';
			    $this->Email->sendAs = 'both';
				
				$this->Email->to = $data['Business']['email'] . ' <' . $data['Business']['email'] . '>';
				$this->set('username', $this->Auth->user('username'));
				$this->Email->send();
				
				$this->flash('Congrats! Your ad is submitted!', 'status');
				$this->redirect ( array ('controller' => 'pages', 'action' => 'home' ) );
	        } 
        } 
	}
	function myads() {
		if($this->RequestHandler->isAjax()) {
			$this->autoRender = $this->layout = false;
			$type = $this->params['form']['type'];
			$id = $this->params['form']['id'];
			switch ($type) {
				case 'Enable':
					$this->Classified->id = $id; 
					$result = $this->Classified->saveField('enabled', 1); 
					break;
				case 'Disable':
					$this->Classified->id = $id;
					$result = $this->Classified->saveField('enabled', 0);
					break;
			}
			
			exit();
		}
		
		$this->set('title_for_layout', 'My Ads');
		$this->Business->bindModel(array('belongsTo' => array('Category')));
		$ads = $this->Classified->find("all", array('conditions' => array('Classified.user_id' => $this->Auth->user('id')), 'recursive' => 2));

		$this->set('ads', $ads);
	}
	
	function editad($classified_id) {
		$this->Classified->unBindModel(array('belongsTo' => array('Profession')));
		$ad = $this->Classified->find('first', array('conditions' => array( 'ClassifiedRegion.classified_id' => $classified_id), 'group' => 'Classified.id', 'joins' => array(array('table' => 'classified_region','alias' => 'ClassifiedRegion','type' => 'left', 'foreignKey' => false, 'conditions'=> array('ClassifiedRegion.classified_id = Classified.id'))), 'recursive' => 2));
		$now = date('Y-m-d H:i:s');
		$charge_for_photo = true;
		$charge_for_video = true;
		$charge_for_custom_ad = true;
		$charge_for_add_description = true;
		$charge_for_top_ad = true;
		if($ad) {
			if($ad['Classified']['user_id'] == $this->Auth->user('id')) {
				$this->set("learn_more", $this->LearnMore->find('list', array("fields"=>array("LearnMore.type", "LearnMore.text"), "conditions"=>array("LearnMore.category_id"=>$ad['Business']['category_id'], "LearnMore.published" => 1))));
				$this->set("provinces", $this->Province->find('list', array("fields"=>array("Province.id", "Province.name"), 'conditions' => array('Province.disapproved' => 0))));
				$this->set("profession", $this->Profession->find('first', array("conditions"=>array("Profession.id"=>$ad['Classified']['profession_id']))));
				if($ad['Business']['category_id'] == $this->contractor_id || $ad['Business']['category_id'] == $this->retail_id) {
					$overall_discount = $this->OverallDiscount->find('first', array("conditions" => array("OverallDiscount.start_date <=" => $now, "OverallDiscount.end_date >=" => $now)));
					$overall_discount_amount = $overall_discount ? $overall_discount['OverallDiscount']['amount'] : 0;
					$this->set('overall_discount', $overall_discount_amount);
					$tax = $this->Tax->find('first');
					$tax_amount = $tax['Tax']['amount'] ? $tax['Tax']['amount'] :0 ; 
					$this->set('tax', $tax_amount);
		
					$this->set('services', $this->Service->find('list', array('fields' => array('Service.name'))));
					$this->set('duration_rates', $this->DurationRate->find('all'));
					$this->set('media_limit', $this->MediaLimit->find('list', array('fields' => array('MediaLimit.limit', 'MediaLimit.rate','MediaLimit.type'))));
					$subtotal_multiplier = 1;
					if($ad['Classified']['expire_date'] && $ad['Classified']['expire_date'] != "") {
						$d1 = strtotime(date ('Y-m-d', strtotime($ad['Classified']['expire_date'])));
						$d2 = time();
						$d3 = strtotime(date('Y-m-d', strtotime($ad['Classified']['expire_date'])));
						$d4 = strtotime(date('Y-m-d', strtotime($ad['Classified']['published_date'])));

						$subtotal_multiplier = ( $d1 - $d2)/( $d3 - $d4);
					}
					$this->set('subtotal_multiplier', $subtotal_multiplier);
				} else {
					$this->set('availabilities', $this->Availability->find('list', array('fields' => array('Availability.name'))));
					$this->Profession->ProfessionSubprofession->bindModel(array('belongsTo'=>array('Subprofession')));
					$this->set('subprofessions',$this->Subprofession->ProfessionSubprofession->find('all' , array('conditions' => array('ProfessionSubprofession.profession_id' => $ad['Classified']['profession_id']),
										'fields' => array('Subprofession.id', 'Subprofession.name'))));
				}
				
				
				if($ad['Classified']['photo']) {
					$this->set('photo_not_removable', true);
					$charge_for_photo = false;
				}
				
				if($ad['Classified']['video']) {
					$this->set('video_not_removable', true);
					$charge_for_video = false;
				}
				
				if($ad['Classified']['custom_ad'] == 1) {
					$this->set('customad_not_removable', true);
					$charge_for_custom_ad = false;
				}
				
				if($ad['Classified']['ad_text'] && $ad['Classified']['ad_text'] != "") {
					$this->set('not_charge_for_ad_description', true);
					$charge_for_add_description = false;
				}
				if($ad['Classified']['top_ad']) {
					$this->set('topad_not_removable', true);
					$charge_for_top_ad = false;
				}
				
				$this->set('not_remove_images', true);
				if(empty($this->data)) {

					
					if(isset($ad['Gallery']['Photo']) && count($ad['Gallery']['Photo'])) {
						foreach ($ad['Gallery']['Photo'] as $key=>$value) {
							$ad['PhotoHelper'][$key + 1] = array('helper' => $value['name'], 
											'description' => $value['description'], 
											'id' => $value['id']);
						}
					}
					
					if(isset($ad['Gallery']['Video']) && count($ad['Gallery']['Video'])) {
						$ad['Video'] = $ad['Gallery']['Video'];
					}
					
					$this->data = $ad;
					switch($ad['Business']['category_id']) {
						case $this->contractor_id:
							$not_removable_areas_count = count($ad['Region']);
							$this->set('not_removable', $not_removable_areas_count);
							$this->set('location_rate_id', $ad['Classified']['location_rate_id']);
							$this->render('contractor_edit_ad');
							break;
						case $this->retail_id:
							$areas = $this->Region->find('list', array("conditions"=>array("Region.province_id"=>$ad['Region'][0]['province_id'], "Region.area_id" => null), 'recursive' => -1));
							if($ad['Region'][0]['area_id']) {
								$cities = $this->Region->find('list', array("conditions"=>array("Region.area_id" => $ad['Region'][0]['area_id']), 'recursive' => -1));
							} else {
								$cities = array();
							}
							$this->set('areas', $areas);
							$this->set('cities', $cities);
							
							$this->render('retailer_edit_ad');
							break;
						case $this->labour_id:
							$this->render('labour_edit_ad');
						break;
					}
				} else { // the form is submitted and $this->data is not empty
					
					// proceed photos
					$new_photos_array = array();
					$old_photos_array = array();
					$removed_photos_array = array();
					$removed_images_names = array();
					
//					if($this->data['Classified']['photo']) {
						foreach ($this->data['PhotoHelper'] as $key => $value) {
							if($key == 'desc') {
								continue;
							}
							if(isset($value['helper']) && $value['helper'] != "") {
								if(@isset($value['id'])) {
									$old_photos_array[] = array('name' => $value['helper'], 'description' => $value['description'], 'id' => $value['id'], 'gallery_id' => $ad['Gallery']['id']);
								} else {
									if($ad['Gallery']['id'] != "") {
										$new_photos_array[] = array('name' => $value['helper'], 'description' => $value['description'], 'gallery_id' => $ad['Gallery']['id']);
									} else {
										$new_photos_array[] = array('name' => $value['helper'], 'description' => $value['description']);	
									}
								}
							}
						}
						
						if(isset($ad['Gallery']['Photo'])) {
							foreach ($ad['Gallery']['Photo'] as $obj1) {
								$deleted = true;
								foreach ($old_photos_array as $obj2) {
									if($obj1['id'] === $obj2['id']) {
										$deleted = false;
										break;
									}
								}
								if($deleted) {
									$removed_photos_array[] = $obj1['id'];
									$removed_images_names[] = $obj1['name'];
								}
							}
						}
						
//					}
					
					$videoValidates = true;
					$new_videos_array = array();
					$old_videos_array = array();
					$removed_videos_array = array();
					switch ($this->data['Business']['category_id']) {
						case $this->contractor_id:
						case $this->retail_id:
							if($this->data['Business']['service'] != "") {
								$this->data['Classified']['service_ids'] = implode(',' ,$this->data['Business']['service']);
							}  else {
								$this->data['Classified']['service_ids'] = "";
							}
							
							
							if($this->data['Classified']['video']) {
								$videoValidates = $this->Video->saveAll($this->data['Video'], array('validate' => 'only'));
								foreach ($this->data['Video'] as $key => $value) {
									if($ad['Gallery']['id'] != "") {
										$value['gallery_id'] = $ad['Gallery']['id']; 
									}
									if(isset($value['id'])) {
										if($value['url'] == "") {
											$removed_videos_array[] = $value['id'];
										} else {
											$old_videos_array[] = $value;
										}
									} else {
										if($value['url'] != "") {
											$new_videos_array[] = $value;
										}
									}
								}
							}
							break;
						case $this->labour_id:
							if($this->data['Business']['availability'] != "") {
								$this->data['Classified']['availability_ids'] = implode(',' ,$this->data['Business']['availability']);
							}  else {
								$this->data['Classified']['availability_ids'] = "";
							}
							
							if($this->data['Classified']['subprofession_ids'] != "") {
								$this->data['Classified']['subprofession_ids'] = implode(',' ,$this->data['Classified']['subprofession_ids']);
							}
							break;
						
					}
					
					if($this->data['Business']['action'] == 'preview') {
						$this->data['Business']['accept_terms'] = 1;
					}
					$this->Business->set($this->data);
					$this->Classified->set($this->data);
					$businessValidates = $this->Business->validates();
					$classifiedValidates = $this->Classified->validates();
					$area_ids = $this->data['Classified']['area_ids'];
					$city_ids = $this->data['Classified']['city_ids'];
					$city_area_ids = $this->data['Classified']['city_area_ids'];
					if($area_ids == "") {
						$area_ids = array();
					} else {
						$area_ids = explode(',', $area_ids);	
					}
					
					if($city_ids == "") {
						$city_ids = array();	
					} else {
						$city_ids = explode(',', $city_ids);
					}
					
					if($city_area_ids == "") {
						$city_area_ids = array();
					} else {
						$city_area_ids = explode(',', $city_area_ids);
					}
					
					if($businessValidates && $classifiedValidates && $videoValidates && $this->data['Business']['action'] == 'save') {
						// if the ad is of category contractor or retail, then calculate payable amount
						$subtotal = 0;
						switch ($this->data['Business']['category_id']) {
							case $this->contractor_id:
							case $this->retail_id:
							$this->data['Classified']['user_id'] = $this->Auth->user('id');
							$this->data['Business']['user_id'] = $this->Auth->user('id');
							if(isset($this->data['Classified']['location_rate_id'])) {
								$this->DurationRate->bindModel(array('hasMany' => array('LocationRate' => array('conditions' => array('LocationRate.id' => $this->data['Classified']['location_rate_id'])))));
								$duration_limitation = $this->DurationRate->find('first', array('conditions' => array('DurationRate.id' => $ad['Classified']['duration_rate_id'])));
								if($this->data['Classified']['location_rate_id'] != $ad['Classified']['location_rate_id']) {
									$subtotal += $duration_limitation['LocationRate'][0]['rate'];
								}
							} else {
								$duration_limitation = $this->DurationRate->find('first', array('conditions' => array('DurationRate.id' => $ad['Classified']['duration_rate_id'])));
							}
							
							if($charge_for_photo || $charge_for_video) {
								$media_limitation = $this->MediaLimit->find('list', array('fields' => array('MediaLimit.limit', 'MediaLimit.rate','MediaLimit.type')));
								
								if($charge_for_photo && $this->data['Classified']['photo']) {
									$photo_limit = $media_limitation['image'];
									foreach ($photo_limit as $key => $value) {
										$subtotal += $photo_limit[$key];
									}
								}
								
								if($charge_for_video && $this->data['Classified']['video']) {
									$video_limit = $media_limitation['video'];
									foreach ($video_limit as $key => $value) {
										$subtotal += $video_limit[$key];
									}
								}
								
								if($charge_for_add_description && isset($this->data['Classified']['add_description']) && $this->data['Classified']['add_description']) {
									$subtotal += $duration_limitation['DurationRate']['description_rate'];
								}
								
								if($charge_for_custom_ad && isset($this->data['Classified']['custom_ad']) && $this->data['Classified']['custom_ad']) {
									$subtotal += $duration_limitation['DurationRate']['customad_rate'];
								}
								
								if($charge_for_top_ad && $this->data['Classified']['top_ad']) {
									$subtotal += $duration_limitation['DurationRate']['topad_rate'];
								}
								
								//calculate subtotal discount and taxes 
								$code = $this->data['Discount']['amount'];
								$discount = $this->_getDiscountAmountByCode($code);
								$discount_amount = $discount['amount'];
								
								$max_discount = $overall_discount_amount > $discount_amount ? $overall_discount_amount : $discount_amount;
								
								$subtotal *= $subtotal_multiplier;
								// apply discount
								$subtotal -= $subtotal * $max_discount / 100;
								 
								// apply tax
								$subtotal += $subtotal * $tax_amount / 100;
								
							}
						}
						// token will be user in a url that will disapprove the ad 
						$token = md5(date('mdY').rand(4000000,4999999));
						$this->data['Classified']['hash'] = $token;
						
						if($subtotal > 0) {
							$subtotal = number_format($subtotal, 2);
						}
						$this->data['Classified']['subtotal'] = $subtotal;
						
						if(isset($this->data['Classified']['add_description']) && ! $this->data['Classified']['add_description']) {
							$this->data['Classified']['ad_text'] = "";							
						}
						
						// set acction type later to access in payment
						$this->data['Classified']['action'] = 'update';
					
						$this->Session->write('subtotal', $subtotal);
						$this->Session->write('submit_ad', $this->data);
						$this->Session->write('ad', $ad);
						$this->Session->write('new_photos_array', $new_photos_array );
						$this->Session->write('new_videos_array', $new_videos_array );
						$this->Session->write('old_videos_array', $old_videos_array );
						$this->Session->write('old_photos_array', $old_photos_array );
						$this->Session->write('removed_videos_array', $removed_videos_array );
						$this->Session->write('removed_photos_array', $removed_photos_array );
						$this->Session->write('removed_images_names', $removed_images_names );
						$this->Session->write('city_area_ids', $city_area_ids );
						$this->Session->write('city_ids', $city_ids );
						$this->Session->write('area_ids', $area_ids );
						
						if($subtotal == 0) {
							$this->Ad->update();
							$this->_sendAdUploadMessageToAdmin($classified_id);
							$this->flash('Your Ad was successfully saved', 'status');
							$this->redirect ( array ('controller' => 'classifieds', 'action' => 'myads' ) );
						} else {
							$this->redirect ( array ('controller' => 'classifieds', 'action' => 'payment' ) );
						}
					} else { // there are vaildation errors, pass back the entered data or the user clicked Preview button
						if($businessValidates && $classifiedValidates && $videoValidates && $this->data['Business']['action'] == 'preview') {
							$this->Profession->unBindModel(array('hasAndBelongsToMany' => array('Subprofession', 'Category')));
							$profession = $this->Profession->find('first', array('conditions' => array('Profession.id' => $ad['Classified']['profession_id'], 'Profession.disapproved' => 0)));
							$this->set('show_preview_popup', 1);	
							$this->set('profession_name', $profession['Profession']['name']);
						}
						
						$region_ids = array();
						foreach ($ad['Region'] as $region) {
							if(in_array($region['id'], $city_ids)) {
								$region_ids[] = $region['id'];
								$city_ids = array_diff($city_ids, array($region['id']));
							}
							
							if(in_array($region['id'], $area_ids)) {
								$region_ids[] = $region['id'];
								$area_ids = array_diff($area_ids, array($region['id']));
							}
						}
						$region_ids = array_merge($region_ids, array_merge($area_ids, $city_ids));
						if(count($region_ids)) {
							$regions = $this->Region->find('all', array( 'conditions' => array('Region.id' => $region_ids, 'Region.disapproved' => 0), 'recursive' => -1) );
						}
						foreach($region_ids as $region_id) {
							foreach ($regions as $region) {
								if($region_id == $region['Region']['id'])
									$this->data['Region'][] = $region['Region'];
							}
						}
						
						$this->log($this->Business->validationErrors, 'edit_add');
						$this->log($this->Classified->validationErrors, 'edit_add');
						
						if($this->data['Business']['category_id'] == $this->contractor_id) {
							$not_removable_areas_count = count($ad['Region']);
							$this->set('location_rate_id', $ad['Classified']['location_rate_id']);
							$this->set('not_removable', $not_removable_areas_count);
							$this->render('contractor_edit_ad');
						} else if($this->data['Business']['category_id'] == $this->retail_id) {
								$areas = $this->Region->find('list', array("conditions"=>array("Region.province_id"=>$this->data['Classified']['province_id'], "Region.area_id" => null), 'recursive' => -1));
								$cities = $this->Region->find('list', array("conditions"=>array("Region.area_id" => $this->data['Classified']['area_id']), 'recursive' => -1));
								$this->set('cities', $cities);
								$this->set('areas', $areas);
							$this->render('retailer_edit_ad');
						} else if($this->data['Business']['category_id'] == $this->labour_id) {
							$this->render('labour_edit_ad');
						} 
					}
					
				}
			} else { // if the user isnt the owner of the ad and wants to edit it, redirect him!
				$this->redirect ( array ('controller' => 'pages', 'action' => 'home' ) );
			}
		} else {
			// the user hasnt posted ads yet
		}
	}
	
	function search() {
		$search_param = "";
		if(isset($this->params['url']['q']) && $this->params['url']['q']) {
			$search_param = $this->params['url']['q'];

			
			$search_param = trim($search_param);
			$search_param = preg_replace('/\s\s+/', ' ', $search_param);
			//Search Phone
			if(preg_match('/^(?:1)?[(-]?[2-9]{1}[0-9]{2}[) -]{0,2}[0-9]{3}[- ]?[0-9]{4}[ ]?$/', $search_param) || preg_match('/^[(]?[2-9]{1}[0-9]{2}[) -]{0,2}[0-9]{3}[- ]?[0-9]{4}[ ]?$/', $search_param)) {
//				$search_param = preg_replace('/\s\s+/', '', $search_param);
				$search_param = str_replace(array(' ','(',')','-'), '', $search_param);
			}
			
			$conditions = array();
			$conditions['OR']['OR']['Business.phone_home'] = $search_param;
			$conditions['OR']['OR']['Business.phone_cell'] = $search_param;
			$conditions['OR']['OR']['Business.phone_office'] = $search_param;
			$conditions['OR']['OR']['Business.fax'] = $search_param;
			
//			$conditions = array();
			$conditions['OR']['Business.businessname LIKE'] = '%' . $search_param . '%';
			
			$this->Classified->unBindModel(array('belongsTo' => array('Profession', 'Gallery'), 'hasAndBelongsToMany' => array('Region')));
			//$this->Business->unBindModel(array('hasMany' => array('Reference')));
			$this->Business->bindModel(array('belongsTo' => array('Category')));
			$classifieds = $this->Classified->find('all', array('conditions' => $conditions, 'order' => 'Business.businessname', 'recursive' => 2));
			
			$this->set('classifieds', $classifieds);
		} else {
			$this->redirect ( array ('controller' => 'pages', 'action' => 'home' ) );
		}
	}
	function _sendAdUploadMessageToAdmin($classified_id) {
		if($classified_id) {
			$this->Business->bindModel(array('belongsTo' => array('Category')));
			$this->Classified->unBindModel(array('hasMany' => array('Reference', 'Review')));
			$this->Profession->unBindModel(array('hasAndBelongsToMany' => array('Category', 'Subprofession')));
			$ad = $this->Classified->find('first', array('conditions' =>array('Classified.id' => $classified_id), 'recursive' => 2));

			$this->Email->smtpOptions = array(
			   'port'=>'465',
			   'timeout'=>'30',
			   'host' => 'ssl://smtp.gmail.com',
			   'username'=> Configure::read('SMPT.email'),
			   'password'=> Configure::read('SMPT.password')
			);
			 
			$this->Email->delivery = 'smtp';
			$this->Email->from = Configure::read('SMPT.name') . ' <' . Configure::read('SMPT.email') . '>';
			$this->Email->to = Configure::read('Admin.name') . ' <' . Configure::read('Admin.email') . '>';
			 
			$this->Email->template = 'ad_upload_message';
			$this->Email->sendAs = 'both';
			$this->Email->subject = "Ad Post/Edit";
			
			
		
			
			$images = array();
			$videos = array();
			
			if($ad['Business']['category_id'] == $this->labour_id) {
				$businessname = $ad['Business']['firstname'] . " " .$ad['Business']['lastname'];  
				$phone_numbers = $ad['Business']['phone_cell'];
				if($ad['Business']['phone_home'] != "") {
					$phone_numbers  .= ', ' . $ad['Business']['phone_home'];
				}
			} else {
				$businessname = $ad['Business']['businessname'];
				if(isset($ad['Gallery']['Photo']) && count($ad['Gallery']['Photo']) > 0 ) {
					foreach($ad['Gallery']['Photo'] as $photo ) {
						$images [] = Router::url('/', true) . 'img/uploads/' . $photo['name'];
					} 
				}
				
				if(isset($ad['Gallery']['Video']) && count($ad['Gallery']['Video']) > 0 ) {
					foreach($ad['Gallery']['Video'] as $video ) {
						$videos [] = $video['url'];
					} 
				}
				
				if($ad['Business']['category_id'] == $this->contractor_id) {
					$phone_numbers = $ad['Business']['phone_office'];
					if($ad['Business']['phone_cell'] != "") {
						$phone_numbers = $ad['Business']['phone_cell'];
					}
				}
			}
			
			if($ad['Business']['category_id'] == $this->retail_id) {
				$address = $ad['Business']['street_number'] . ' ' . $ad['Business']['unit_number'] . ' ' . $ad['Business']['street_name'] . 
				 			' ' . $ad['Business']['city'] . ' ' . $ad['Business']['postal_code'];
				$phone_numbers = $ad['Business']['phone_office'];
				if($ad['Business']['fax'] != "") {
					$phone_numbers  .= ', ' . $ad['Business']['fax'];
				}
				$this->set('address', $address);
			}
			$regions = array();
			foreach($ad['Region'] as $region) {
				$regions [] = $region['Province']['name'] . '/' . $region['name'];
			}
			
			if($ad['Classified']['ad_text'] != "") {
				$this->set('description', $ad['Classified']['ad_text']);
			}
			$this->set('classified_id', $classified_id);
			$this->set('username', $this->Auth->user('username'));
			$this->set('category', $ad['Business']['Category']['name']);
			$this->set('profession', $ad['Profession']['name']);
			$this->set('business_name', $businessname);
			$this->set('regions', $regions);
			
			$this->set('phone_numbers', $phone_numbers);
			$this->set('images', $images);
			$this->set('videos', $videos);
			$this->set('disapprove_link',  Router::url('/', true) . 'classifieds/disapprove_ad/' . $ad['Classified']['hash']);
			
			
			$this->log($this->Email->send(), 'classified');
		}
	}
	
	function disapprove_ad($hash) {
		$this->set('title_for_layout', 'Post Ad');
		if($hash) {
			$ad = $this->Classified->find('first', array('conditions' =>array('Classified.hash' => $hash), 'recursive' => -1));
			if($ad) {
				$ad['Classified']['approved'] = 0;
				$ad['Classified']['hash'] = null;
				$this->Classified->set($ad);
				$this->Classified->save();
				$this->set('message', 'The Ad is now disapproved.');
			} else {
				$this->set('message', 'This page is expired.');
			}
		}
	}
}