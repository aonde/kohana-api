<?php

abstract class Controller_API_ORM extends Controller_API {

	/**
	 * @var string Model Name
	 */
	protected $_model_name = NULL;

	/**
	 * @var integer Default number of results per request
	 */
	protected $_limit_default = 25;

	/**
	 * @var integer Max results per request
	 */
	protected $_limit_max = 100;

	public function before()
	{
		parent::before();

		if ($this->_model_name === NULL)
			throw new Kohana_Exception('A model name is required');
	}

	public function after()
	{
		$this->_response_metadata += array(
			'type' => $this->_model_name,
		);

//		$this->_response_links += array(
//			'create' => $this->_generate_link('POST', Route::url('api', array(
//				'controller' => $this->request->controller(),
//				'id'         => NULL,
//			), TRUE)),
//			'read' => $this->_generate_link('GET', Route::url('api', array(
//				'controller' => $this->request->controller(),
//				'id'         => ':id',
//			), TRUE), array(
//				':id' => 'id',
//			)),
//			'update' => $this->_generate_link('PUT', Route::url('api', array(
//				'controller' => $this->request->controller(),
//				'id'         => ':id',
//			), TRUE), array(
//				':id' => 'id',
//			)),
//			'delete' => $this->_generate_link('DELETE', Route::url('api', array(
//				'controller' => $this->request->controller(),
//				'id'         => ':id',
//			), TRUE), array(
//				':id' => 'id',
//			)),
//		);

		$model = ORM::factory($this->_model_name);

		// Add links for has_many relations
		foreach ($model->has_many() as $name => $opts)
		{
			$this->_response_links[$name] = $this->_generate_link('GET', Route::url('api', array(
				'controller' => Inflector::plural($opts['model']),
			), TRUE).'?where.'.$opts['foreign_key'].'.eq=:'.$opts['foreign_key'], array(
				':'.$opts['foreign_key'] => 'id',
			));
		}

		// Add links for belongs_to relations
		foreach ($model->belongs_to() as $name => $opts)
		{
			$this->_response_links[$name] = $this->_generate_link('GET', Route::url('api', array(
				'controller' => Inflector::plural($opts['model']),
				'id'         => ':id',
			), TRUE), array(
				':id' => $opts['foreign_key'],
			));
		}

		parent::after();
	}

	protected function _execute($action) {
		try
		{
			parent::_execute($action);
		}
		catch (ORM_Validation_Exception $e)
		{
			$this->response->status(400);

			$this->_response_metadata = array(
				'error' => TRUE,
				'type'  => 'error_validation',
			);

			$this->_response_links = array();

			$this->_response_payload = array(
				'errors' => $e->errors(),
			);
		}
	}

	/**
	 * GET /api/:model_name_plural/:id
	 */
	public function get() {
		$id = $this->request->param('id', FALSE);

		$object = ORM::factory($this->_model_name, $id);

		$this->_response_payload = $object->as_array();

	}

	/**
	 * GET /api/:model_name_plural
	 */
	public function get_collection() {
		$model = ORM::factory($this->_model_name);

		// Apply filters
		$query = $this->request->query();

		$model = $this->_apply_filters($model, $query);

		$cmodel = clone $model;

		// Apply Limit/Offset
		$limit = $this->request->query('limit');
		$offset = $this->request->query('offset');

		if ($offset === NULL) {
			$offset = 0;
		}

		if ($limit === NULL) {
			$limit = $this->_limit_default;
		} else if ((int) $limit > $this->_limit_max) {
			$limit = $this->_limit_max;
		}

		$model->limit($limit)->offset($offset);

		// Lets go ..
		$objects_total_count = $cmodel->count_all();
		$objects = $model->find_all();

		// Prepare response
		$this->_response_metadata += array(
			'total' => (int) $objects_total_count,
			'fetched' => (int) $objects->count(),
			'offset' => (int) $offset,
			'limit' => (int) $limit,
		);

		$this->_response_payload = $objects->map(function($val) {
			return $val->as_array();
		});
	}

	/**
	 * Create a new company
	 *
	 * POST /api/:model_name_plural
	 */
	public function post_collection() {
		$object = ORM::factory($this->_model_name);

//		$object->values($this->_request_payload, array(
//			'name',
//		))->save();

		$object->values($this->_request_payload)->save();

		$this->_response_payload = $object->as_array();
	}

	/**
	 * Update a company
	 *
	 * PUT /api/:model_name_plural/:id
	 */
	public function put() {
		$id = $this->request->param('id', FALSE);

		$object = ORM::factory($this->_model_name, $id);

//		$object->values($this->_request_payload, array(
//			'name',
//		))->save();

		$object->values($this->_request_payload)->save();

		$this->_response_payload = $object->as_array();
	}

	/**
	 * Delete a company
	 *
	 * DELETE /api/:model_name_plural/:id
	 */
	public function delete() {
		$id = $this->request->param('id', FALSE);

		$object = ORM::factory($this->_model_name, $id);

		$object->values($this->_request_payload, array(
			'name',
		))->save();
	}

	/**
	 * Delete all companies
	 *
	 * DELETE /api/:model_name_plural
	 */
	public function delete_collection() {
		$objects = ORM::factory($this->_model_name)->find_all();

		foreach ($objects as $object) {
			$object->delete();
		}
	}

	protected function _apply_filters($model, $params)
	{
		foreach ($params as $name => $value)
		{
			if (strpos($name, 'where.') !== FALSE)
			{
				$parts = explode('.', $name);

				$column = $parts[1];
				$condition = $parts[2];

				switch ($condition)
				{
					case 'eq': // Equals
						$model->where($column, '=', $value);
						break;
					case 'lk': // Like
						$model->where($column, 'LIKE', $value);
						break;
					case 'lt': // Less Than
						$model->where($column, '<', $value);
						break;
					case 'gt': // Greater Than
						$model->where($column, '>', $value);
						break;
					default:
						throw new HTTP_Exception_400('Unknown where condition \':condition\'', array(
							':condition' => $condition,
						));
				}
			}
		}

		return $model;
	}
}