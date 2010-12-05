<?php
/**
	CakePHP Filter Plugin

	Copyright (C) 2009-3827 dr. Hannibal Lecter / lecterror
	<http://lecterror.com/>

	Multi-licensed under:
		MPL <http://www.mozilla.org/MPL/MPL-1.1.html>
		LGPL <http://www.gnu.org/licenses/lgpl.html>
		GPL <http://www.gnu.org/licenses/gpl.html>
*/

App::import('Component', 'Filter.Filter');
require_once(dirname(dirname(dirname(__FILE__))) . DS . 'mock_objects.php');

class FilterTestCase extends CakeTestCase
{
	var $fixtures = array
		(
			'plugin.filter.document_category',
			'plugin.filter.document',
			'plugin.filter.item',
			'plugin.filter.metadata',
		);

	var $Controller = null;

	function startTest($method)
	{
		Router::connect('/', array('controller' => 'document_tests', 'action' => 'index'));
		$this->Controller = ClassRegistry::init('DocumentTestsController');
		$this->Controller->params = Router::parse('/');
		$this->Controller->params['url']['url'] = '/';
		$this->Controller->action = $this->Controller->params['action'];
		$this->Controller->uses = array('Document');

		if (array_search($method, array('testPersistence')) !== false)
		{
			$this->Controller->components = array('Session', 'Filter.Filter' => array('nopersist' => true));
		}
		else
		{
			$this->Controller->components = array('Session', 'Filter.Filter');
		}

		$this->Controller->constructClasses();
		$this->Controller->Session->destroy();
	}

	function endTest()
	{
		$this->Controller = null;
	}

	/**
	 * Test bailing out when no filters are present.
	 */
	function testNoFilters()
	{
		$this->Controller->Component->initialize($this->Controller);
		$this->assertTrue(empty($this->Controller->Filter->settings));
		$this->assertFalse($this->Controller->Document->Behaviors->enabled('Filtered'));

		$this->Controller->Component->startup($this->Controller);
		$this->assertFalse(in_array('Filter.Filter', $this->Controller->helpers));
	}

	/**
	 * Test bailing out when a filter model can't be found
	 * or when the current action has no filters.
	 */
	function testNoModelPresentOrNoActionFilters()
	{
		$testSettings = array
			(
				'index' => array
				(
					'DocumentArse' => array
					(
						'DocumentFeck.drink' => array('type' => 'irrelevant')
					)
				)
			);

		$this->expectError();
		$this->Controller->filters = $testSettings;
		$this->Controller->Component->initialize($this->Controller);

		$testSettings = array
			(
				'someotheraction' => array
				(
					'Document' => array
					(
						'Document.title' => array('type' => 'text')
					)
				)
			);


		$this->Controller->filters = $testSettings;
		$this->Controller->Component->initialize($this->Controller);
		$this->assertFalse($this->Controller->Document->Behaviors->enabled('Filtered'));

		$testSettings = array
			(
				'index' => array
				(
					'Document' => array
					(
						'Document.title' => array('type' => 'text')
					)
				),
			);

		$this->Controller->filters = $testSettings;
		$this->Controller->Component->initialize($this->Controller);
		$this->assertTrue($this->Controller->Document->Behaviors->enabled('Filtered'));
	}

	/**
	 * Test basic filter settings.
	 */
	function testBasicFilters()
	{
		$testSettings = array
			(
				'index' => array
				(
					'Document' => array
					(
						'Document.title' => array('type' => 'text')
					)
				)
			);
		$this->Controller->filters = $testSettings;

		$expected = array
			(
				$this->Controller->name => $testSettings
			);

		$this->Controller->Component->initialize($this->Controller);
		$this->assertEqual($expected, $this->Controller->Filter->settings);
	}

	/**
	 * Test running a component with no filter data.
	 */
	function testEmptyStartup()
	{
		$testSettings = array
			(
				'index' => array
				(
					'Document' => array
					(
						'Document.title' => array('type' => 'text')
					)
				)
			);
		$this->Controller->filters = $testSettings;


		$this->Controller->Component->initialize($this->Controller);
		$this->Controller->Component->startup($this->Controller);
		$this->assertTrue(in_array('Filter.Filter', $this->Controller->helpers));
	}

	/**
	 * Test loading filter data from session (both full and empty).
	 */
	function testSessionStartupData()
	{
		$testSettings = array
			(
				'index' => array
				(
					'Document' => array
					(
						'Document.title' => array('type' => 'text')
					),
					'FakeNonexistant' => array
					(
						'drink' => array('type' => 'select')
					)
				)
			);
		$this->Controller->filters = $testSettings;

		$sessionKey = sprintf('FilterPlugin.Filters.%s.%s', $this->Controller->name, $this->Controller->action);

		$filterValues = array();
		$this->Controller->Session->write($sessionKey, $filterValues);
		$this->expectError();
		$this->Controller->Component->initialize($this->Controller);

		$this->expectError();
		$this->Controller->Component->startup($this->Controller);
		$this->assertEqual
			(
				$this->Controller->Document->Behaviors->Filtered->_filterValues[$this->Controller->Document->alias],
				$filterValues
			);

		$filterValues = array('Document' => array('title' => 'in'));
		$this->Controller->Session->write($sessionKey, $filterValues);

		$this->Controller->Component->startup($this->Controller);
		$this->assertEqual
			(
				$this->Controller->Document->Behaviors->Filtered->_filterValues[$this->Controller->Document->alias],
				$filterValues
			);

		$this->Controller->Session->delete($sessionKey);
	}

	/**
	 * Test loading filter data from a post request.
	 */
	function testPostStartupData()
	{
		$_SERVER['REQUEST_METHOD'] = 'POST';

		$testSettings = array
			(
				'index' => array
				(
					'Document' => array
					(
						'Document.title' => array('type' => 'text')
					),
				)
			);

		$this->Controller->filters = $testSettings;

		$filterValues = array('Document' => array('title' => 'in'), 'Filter' => array('filterFormId' => 'Document'));
		$this->Controller->data = $filterValues;

		$this->Controller->Component->initialize($this->Controller);
		$this->Controller->Component->startup($this->Controller);

		$sessionKey = sprintf('FilterPlugin.Filters.%s.%s', $this->Controller->name, $this->Controller->action);
		$sessionData = $this->Controller->Session->read($sessionKey);
		$this->assertEqual($filterValues, $sessionData);

		$this->assertEqual
			(
				$this->Controller->Document->Behaviors->Filtered->_filterValues[$this->Controller->Document->alias],
				$filterValues
			);
	}

	/**
	 * Test exiting beforeRender when in an action with no settings.
	 */
	function testBeforeRenderAbort()
	{
		$testSettings = array
			(
				'veryMuchNotIndex' => array
				(
					'Document' => array
					(
						'Document.title' => array('type' => 'text')
					)
				)
			);
		$this->Controller->filters = $testSettings;

		$expected = array
			(
				$this->Controller->name => $testSettings
			);

		$this->Controller->Component->initialize($this->Controller);
		$this->Controller->Component->startup($this->Controller);
		$this->Controller->Component->beforeRender($this->Controller);

		$this->assertFalse(isset($this->Controller->viewVars['viewFilterParams']));
	}

	/**
	 * Test triggering an error when the plugin runs into a setting
	 * for filtering a model which cannot be found.
	 */
	function testNoModelFound()
	{
		$testSettings = array
			(
				'index' => array
				(
					'ThisModelDoesNotExist' => array
					(
						'ThisModelDoesNotExist.title' => array('type' => 'text')
					)
				)
			);
		$this->Controller->filters = $testSettings;

		$expected = array
			(
				$this->Controller->name => $testSettings
			);

		$this->expectError();
		$this->Controller->Component->initialize($this->Controller);

		//$this->expectError();
		$this->Controller->Component->startup($this->Controller);

		$this->expectError();
		$this->Controller->Component->beforeRender($this->Controller);
	}

	/**
	 * Test the view variable generation for very basic filtering.
	 * Also tests model name detection and custom label.
	 */
	function testBasicViewInfo()
	{
		$testSettings = array
			(
				'index' => array
				(
					'Document' => array
					(
						'title',
						'DocumentCategory.id' => array('type' => 'select', 'label' => 'Category'),
					)
				)
			);
		$this->Controller->filters = $testSettings;

		$this->Controller->Component->initialize($this->Controller);
		$this->Controller->Component->startup($this->Controller);
		$this->Controller->Component->beforeRender($this->Controller);

		$expected = array
			(
				array('name' => 'Document.title', 'options' => array('type' => 'text')),
				array
				(
					'name' => 'DocumentCategory.id',
					'options' => array
					(
						'type' => 'select',
						'options' => array
						(
							1 => 'Testing Doc',
							2 => 'Imaginary Spec',
							3 => 'Nonexistant data',
							4 => 'Illegal explosives DIY',
							5 => 'Father Ted',
						),
						'empty' => false,
						'label' => 'Category',
					)
				),
			);

		$this->assertEqual($this->Controller->viewVars['viewFilterParams'], $expected);
	}

	/**
	 * Test passing additional inputOptions to the form
	 * helper, used to customize search form.
	 */
	function testAdditionalInputOptions()
	{
		$testSettings = array
			(
				'index' => array
				(
					'Document' => array
					(
						'title' => array('inputOptions' => 'disabled'),
						'DocumentCategory.id' => array
						(
							'type' => 'select',
							'label' => 'Category',
							'inputOptions' => array('class' => 'important')
						),
					)
				)
			);
		$this->Controller->filters = $testSettings;

		$this->Controller->Component->initialize($this->Controller);
		$this->Controller->Component->startup($this->Controller);
		$this->Controller->Component->beforeRender($this->Controller);

		$expected = array
			(
				array
				(
					'name' => 'Document.title',
					'options' => array
					(
						'type' => 'text',
						'disabled'
					)
				),
				array
				(
					'name' => 'DocumentCategory.id',
					'options' => array
					(
						'type' => 'select',
						'options' => array
						(
							1 => 'Testing Doc',
							2 => 'Imaginary Spec',
							3 => 'Nonexistant data',
							4 => 'Illegal explosives DIY',
							5 => 'Father Ted',
						),
						'empty' => false,
						'label' => 'Category',
						'class' => 'important',
					)
				),
			);

		$this->assertEqual($this->Controller->viewVars['viewFilterParams'], $expected);
	}

	/**
	 * Test data fetching for select input when custom selector
	 * and custom options are provided.
	 */
	function testCustomSelector()
	{
		$testSettings = array
			(
				'index' => array
				(
					'Document' => array
					(
						'DocumentCategory.id' => array
						(
							'type' => 'select',
							'label' => 'Category',
							'selector' => 'customSelector',
							'selectOptions' => array('conditions' => array('DocumentCategory.description LIKE' => '%!%')),
						),
					)
				)
			);
		$this->Controller->filters = $testSettings;

		$this->Controller->Component->initialize($this->Controller);
		$this->Controller->Component->startup($this->Controller);
		$this->Controller->Component->beforeRender($this->Controller);

		$expected = array
			(
				array
				(
					'name' => 'DocumentCategory.id',
					'options' => array
					(
						'type' => 'select',
						'options' => array
						(
							1 => 'Testing Doc',
							5 => 'Father Ted',
						),
						'empty' => false,
						'label' => 'Category',
					)
				),
			);

		$this->assertEqual($this->Controller->viewVars['viewFilterParams'], $expected);
	}

	/**
	 * Test checkbox input filtering.
	 */
	function testCheckboxOptions()
	{
		$testSettings = array
			(
				'index' => array
				(
					'Document' => array
					(
						'Document.is_private' => array
						(
							'type' => 'checkbox',
							'label' => 'Private?',
							'default' => true,
						),
					)
				)
			);
		$this->Controller->filters = $testSettings;

		$this->Controller->Component->initialize($this->Controller);
		$this->Controller->Component->startup($this->Controller);
		$this->Controller->Component->beforeRender($this->Controller);

		$expected = array
			(
				array
				(
					'name' => 'Document.is_private',
					'options' => array
					(
						'type' => 'checkbox',
						'checked' => true,
						'label' => 'Private?',
					)
				),
			);

		$this->assertEqual($this->Controller->viewVars['viewFilterParams'], $expected);
	}

	/**
	 * Test disabling persistence for single action
	 * and for the entire controller.
	 */
	function testPersistence()
	{
		$testSettings = array
			(
				'index' => array
				(
					'Document' => array
					(
						'Document.title' => array('type' => 'text')
					),
				)
			);
		$this->Controller->filters = $testSettings;

		$sessionKey = sprintf('FilterPlugin.Filters.%s.%s', 'SomeOtherController', $this->Controller->action);
		$filterValues = array('Document' => array('title' => 'in'), 'Filter' => array('filterFormId' => 'Document'));
		$this->Controller->Session->write($sessionKey, $filterValues);

		$sessionKey = sprintf('FilterPlugin.Filters.%s.%s', $this->Controller->name, $this->Controller->action);
		$filterValues = array('Document' => array('title' => 'in'), 'Filter' => array('filterFormId' => 'Document'));
		$this->Controller->Session->write($sessionKey, $filterValues);

		$this->Controller->Filter->nopersist[$this->Controller->name] = true;
		$this->Controller->Filter->nopersist['SomeOtherController'] = true;

		$this->Controller->Component->initialize($this->Controller);
		$this->Controller->Component->startup($this->Controller);

		$expected = array($this->Controller->name => array($this->Controller->action => $filterValues));
		$this->assertEqual($this->Controller->Session->read('FilterPlugin.Filters'), $expected);
	}
}