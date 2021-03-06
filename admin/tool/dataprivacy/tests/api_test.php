<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * API tests.
 *
 * @package    tool_dataprivacy
 * @copyright  2018 Jun Pataleta
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use core\invalid_persistent_exception;
use core\task\manager;
use tool_dataprivacy\contextlist_context;
use tool_dataprivacy\context_instance;
use tool_dataprivacy\api;
use tool_dataprivacy\data_registry;
use tool_dataprivacy\expired_context;
use tool_dataprivacy\data_request;
use tool_dataprivacy\purpose;
use tool_dataprivacy\category;
use tool_dataprivacy\local\helper;
use tool_dataprivacy\task\initiate_data_request_task;
use tool_dataprivacy\task\process_data_request_task;

defined('MOODLE_INTERNAL') || die();
global $CFG;

/**
 * API tests.
 *
 * @package    tool_dataprivacy
 * @copyright  2018 Jun Pataleta
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class tool_dataprivacy_api_testcase extends advanced_testcase {

    /**
     * Ensure that the check_can_manage_data_registry function fails cap testing when a user without capabilities is
     * tested with the default context.
     */
    public function test_check_can_manage_data_registry_admin() {
        $this->resetAfterTest();

        $this->setAdminUser();
        // Technically this actually returns void, but assertNull will suffice to avoid a pointless test.
        $this->assertNull(api::check_can_manage_data_registry());
    }

    /**
     * Ensure that the check_can_manage_data_registry function fails cap testing when a user without capabilities is
     * tested with the default context.
     */
    public function test_check_can_manage_data_registry_without_cap_default() {
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $this->expectException(required_capability_exception::class);
        api::check_can_manage_data_registry();
    }

    /**
     * Ensure that the check_can_manage_data_registry function fails cap testing when a user without capabilities is
     * tested with the default context.
     */
    public function test_check_can_manage_data_registry_without_cap_system() {
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $this->expectException(required_capability_exception::class);
        api::check_can_manage_data_registry(\context_system::instance()->id);
    }

    /**
     * Ensure that the check_can_manage_data_registry function fails cap testing when a user without capabilities is
     * tested with the default context.
     */
    public function test_check_can_manage_data_registry_without_cap_own_user() {
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $this->expectException(required_capability_exception::class);
        api::check_can_manage_data_registry(\context_user::instance($user->id)->id);
    }

    /**
     * Test for api::update_request_status().
     */
    public function test_update_request_status() {
        $this->resetAfterTest();

        $generator = new testing_data_generator();
        $s1 = $generator->create_user();
        $this->setUser($s1);

        // Create the sample data request.
        $datarequest = api::create_data_request($s1->id, api::DATAREQUEST_TYPE_EXPORT);

        $requestid = $datarequest->get('id');

        // Update with a comment.
        $comment = 'This is an example of a comment';
        $result = api::update_request_status($requestid, api::DATAREQUEST_STATUS_AWAITING_APPROVAL, 0, $comment);
        $this->assertTrue($result);
        $datarequest = new data_request($requestid);
        $this->assertStringEndsWith($comment, $datarequest->get('dpocomment'));

        // Update with a comment which will be trimmed.
        $result = api::update_request_status($requestid, api::DATAREQUEST_STATUS_AWAITING_APPROVAL, 0, '  ');
        $this->assertTrue($result);
        $datarequest = new data_request($requestid);
        $this->assertStringEndsWith($comment, $datarequest->get('dpocomment'));

        // Update with a comment.
        $secondcomment = '  - More comments -  ';
        $result = api::update_request_status($requestid, api::DATAREQUEST_STATUS_AWAITING_APPROVAL, 0, $secondcomment);
        $this->assertTrue($result);
        $datarequest = new data_request($requestid);
        $this->assertRegExp("/.*{$comment}.*{$secondcomment}/s", $datarequest->get('dpocomment'));

        // Update with a valid status.
        $result = api::update_request_status($requestid, api::DATAREQUEST_STATUS_DOWNLOAD_READY);
        $this->assertTrue($result);

        // Fetch the request record again.
        $datarequest = new data_request($requestid);
        $this->assertEquals(api::DATAREQUEST_STATUS_DOWNLOAD_READY, $datarequest->get('status'));

        // Update with an invalid status.
        $this->expectException(invalid_persistent_exception::class);
        api::update_request_status($requestid, -1);
    }

    /**
     * Test for api::get_site_dpos() when there are no users with the DPO role.
     */
    public function test_get_site_dpos_no_dpos() {
        $this->resetAfterTest();

        $admin = get_admin();

        $dpos = api::get_site_dpos();
        $this->assertCount(1, $dpos);
        $dpo = reset($dpos);
        $this->assertEquals($admin->id, $dpo->id);
    }

    /**
     * Test for api::get_site_dpos() when there are no users with the DPO role.
     */
    public function test_get_site_dpos() {
        global $DB;

        $this->resetAfterTest();

        $generator = new testing_data_generator();
        $u1 = $generator->create_user();
        $u2 = $generator->create_user();

        $context = context_system::instance();

        // Give the manager role with the capability to manage data requests.
        $managerroleid = $DB->get_field('role', 'id', array('shortname' => 'manager'));
        assign_capability('tool/dataprivacy:managedatarequests', CAP_ALLOW, $managerroleid, $context->id, true);
        // Assign u1 as a manager.
        role_assign($managerroleid, $u1->id, $context->id);

        // Give the editing teacher role with the capability to manage data requests.
        $editingteacherroleid = $DB->get_field('role', 'id', array('shortname' => 'editingteacher'));
        assign_capability('tool/dataprivacy:managedatarequests', CAP_ALLOW, $editingteacherroleid, $context->id, true);
        // Assign u1 as an editing teacher as well.
        role_assign($editingteacherroleid, $u1->id, $context->id);
        // Assign u2 as an editing teacher.
        role_assign($editingteacherroleid, $u2->id, $context->id);

        // Only map the manager role to the DPO role.
        set_config('dporoles', $managerroleid, 'tool_dataprivacy');

        $dpos = api::get_site_dpos();
        $this->assertCount(1, $dpos);
        $dpo = reset($dpos);
        $this->assertEquals($u1->id, $dpo->id);
    }

    /**
     * Test for \tool_dataprivacy\api::get_assigned_privacy_officer_roles().
     */
    public function test_get_assigned_privacy_officer_roles() {
        global $DB;

        $this->resetAfterTest();

        // Erroneously set the manager roles as the PO, even if it doesn't have the managedatarequests capability yet.
        $managerroleid = $DB->get_field('role', 'id', array('shortname' => 'manager'));
        set_config('dporoles', $managerroleid, 'tool_dataprivacy');
        // Get the assigned PO roles when nothing has been set yet.
        $roleids = api::get_assigned_privacy_officer_roles();
        // Confirm that the returned list is empty.
        $this->assertEmpty($roleids);

        $context = context_system::instance();

        // Give the manager role with the capability to manage data requests.
        assign_capability('tool/dataprivacy:managedatarequests', CAP_ALLOW, $managerroleid, $context->id, true);

        // Give the editing teacher role with the capability to manage data requests.
        $editingteacherroleid = $DB->get_field('role', 'id', array('shortname' => 'editingteacher'));
        assign_capability('tool/dataprivacy:managedatarequests', CAP_ALLOW, $editingteacherroleid, $context->id, true);

        // Get the non-editing teacher role ID.
        $teacherroleid = $DB->get_field('role', 'id', array('shortname' => 'teacher'));

        // Erroneously map the manager and the non-editing teacher roles to the PO role.
        $badconfig = $managerroleid . ',' . $teacherroleid;
        set_config('dporoles', $badconfig, 'tool_dataprivacy');

        // Get the assigned PO roles.
        $roleids = api::get_assigned_privacy_officer_roles();

        // There should only be one PO role.
        $this->assertCount(1, $roleids);
        // Confirm it contains the manager role.
        $this->assertContains($managerroleid, $roleids);
        // And it does not contain the editing teacher role.
        $this->assertNotContains($editingteacherroleid, $roleids);
    }

    /**
     * Test for api::approve_data_request().
     */
    public function test_approve_data_request() {
        global $DB;

        $this->resetAfterTest();

        $generator = new testing_data_generator();
        $s1 = $generator->create_user();
        $u1 = $generator->create_user();

        $context = context_system::instance();

        // Manager role.
        $managerroleid = $DB->get_field('role', 'id', array('shortname' => 'manager'));
        // Give the manager role with the capability to manage data requests.
        assign_capability('tool/dataprivacy:managedatarequests', CAP_ALLOW, $managerroleid, $context->id, true);
        // Assign u1 as a manager.
        role_assign($managerroleid, $u1->id, $context->id);

        // Map the manager role to the DPO role.
        set_config('dporoles', $managerroleid, 'tool_dataprivacy');

        // Create the sample data request.
        $this->setUser($s1);
        $datarequest = api::create_data_request($s1->id, api::DATAREQUEST_TYPE_EXPORT);
        $requestid = $datarequest->get('id');

        // Make this ready for approval.
        api::update_request_status($requestid, api::DATAREQUEST_STATUS_AWAITING_APPROVAL);

        $this->setUser($u1);
        $result = api::approve_data_request($requestid);
        $this->assertTrue($result);
        $datarequest = new data_request($requestid);
        $this->assertEquals($u1->id, $datarequest->get('dpo'));
        $this->assertEquals(api::DATAREQUEST_STATUS_APPROVED, $datarequest->get('status'));

        // Test adhoc task creation.
        $adhoctasks = manager::get_adhoc_tasks(process_data_request_task::class);
        $this->assertCount(1, $adhoctasks);
    }

    /**
     * Test for api::approve_data_request() with the request not yet waiting for approval.
     */
    public function test_approve_data_request_not_yet_ready() {
        global $DB;

        $this->resetAfterTest();

        $generator = new testing_data_generator();
        $s1 = $generator->create_user();
        $u1 = $generator->create_user();

        $context = context_system::instance();

        // Manager role.
        $managerroleid = $DB->get_field('role', 'id', array('shortname' => 'manager'));
        // Give the manager role with the capability to manage data requests.
        assign_capability('tool/dataprivacy:managedatarequests', CAP_ALLOW, $managerroleid, $context->id, true);
        // Assign u1 as a manager.
        role_assign($managerroleid, $u1->id, $context->id);

        // Map the manager role to the DPO role.
        set_config('dporoles', $managerroleid, 'tool_dataprivacy');

        // Create the sample data request.
        $this->setUser($s1);
        $datarequest = api::create_data_request($s1->id, api::DATAREQUEST_TYPE_EXPORT);
        $requestid = $datarequest->get('id');

        $this->setUser($u1);
        $this->expectException(moodle_exception::class);
        api::approve_data_request($requestid);
    }

    /**
     * Test for api::approve_data_request() when called by a user who doesn't have the DPO role.
     */
    public function test_approve_data_request_non_dpo_user() {
        $this->resetAfterTest();

        $generator = new testing_data_generator();
        $student = $generator->create_user();
        $teacher = $generator->create_user();

        // Create the sample data request.
        $this->setUser($student);
        $datarequest = api::create_data_request($student->id, api::DATAREQUEST_TYPE_EXPORT);

        $requestid = $datarequest->get('id');
    }

    /**
     * Test for api::can_contact_dpo()
     */
    public function test_can_contact_dpo() {
        $this->resetAfterTest();

        // Default ('contactdataprotectionofficer' is disabled by default).
        $this->assertFalse(api::can_contact_dpo());

        // Enable.
        set_config('contactdataprotectionofficer', 1, 'tool_dataprivacy');
        $this->assertTrue(api::can_contact_dpo());

        // Disable again.
        set_config('contactdataprotectionofficer', 0, 'tool_dataprivacy');
        $this->assertFalse(api::can_contact_dpo());
    }

    /**
     * Test for api::can_manage_data_requests()
     */
    public function test_can_manage_data_requests() {
        global $DB;

        $this->resetAfterTest();

        // No configured site DPOs yet.
        $admin = get_admin();
        $this->assertTrue(api::can_manage_data_requests($admin->id));

        $generator = new testing_data_generator();
        $dpo = $generator->create_user();
        $nondpocapable = $generator->create_user();
        $nondpoincapable = $generator->create_user();

        $context = context_system::instance();

        // Manager role.
        $managerroleid = $DB->get_field('role', 'id', array('shortname' => 'manager'));
        // Give the manager role with the capability to manage data requests.
        assign_capability('tool/dataprivacy:managedatarequests', CAP_ALLOW, $managerroleid, $context->id, true);
        // Assign u1 as a manager.
        role_assign($managerroleid, $dpo->id, $context->id);

        // Editing teacher role.
        $editingteacherroleid = $DB->get_field('role', 'id', array('shortname' => 'editingteacher'));
        // Give the editing teacher role with the capability to manage data requests.
        assign_capability('tool/dataprivacy:managedatarequests', CAP_ALLOW, $managerroleid, $context->id, true);
        // Assign u2 as an editing teacher.
        role_assign($editingteacherroleid, $nondpocapable->id, $context->id);

        // Map only the manager role to the DPO role.
        set_config('dporoles', $managerroleid, 'tool_dataprivacy');

        // User with capability and has DPO role.
        $this->assertTrue(api::can_manage_data_requests($dpo->id));
        // User with capability but has no DPO role.
        $this->assertFalse(api::can_manage_data_requests($nondpocapable->id));
        // User without the capability and has no DPO role.
        $this->assertFalse(api::can_manage_data_requests($nondpoincapable->id));
    }

    /**
     * Test that a user who has no capability to make any data requests for children cannot create data requests for any
     * other user.
     */
    public function test_can_create_data_request_for_user_no() {
        $this->resetAfterTest();

        $parent = $this->getDataGenerator()->create_user();
        $otheruser = $this->getDataGenerator()->create_user();

        $this->setUser($parent);
        $this->assertFalse(api::can_create_data_request_for_user($otheruser->id));
    }

    /**
     * Test that a user who has the capability to make any data requests for one other user cannot create data requests
     * for any other user.
     */
    public function test_can_create_data_request_for_user_some() {
        $this->resetAfterTest();

        $parent = $this->getDataGenerator()->create_user();
        $child = $this->getDataGenerator()->create_user();
        $otheruser = $this->getDataGenerator()->create_user();

        $systemcontext = \context_system::instance();
        $parentrole = $this->getDataGenerator()->create_role();
        assign_capability('tool/dataprivacy:makedatarequestsforchildren', CAP_ALLOW, $parentrole, $systemcontext);
        role_assign($parentrole, $parent->id, \context_user::instance($child->id));

        $this->setUser($parent);
        $this->assertFalse(api::can_create_data_request_for_user($otheruser->id));
    }

    /**
     * Test that a user who has the capability to make any data requests for one other user cannot create data requests
     * for any other user.
     */
    public function test_can_create_data_request_for_user_own_child() {
        $this->resetAfterTest();

        $parent = $this->getDataGenerator()->create_user();
        $child = $this->getDataGenerator()->create_user();

        $systemcontext = \context_system::instance();
        $parentrole = $this->getDataGenerator()->create_role();
        assign_capability('tool/dataprivacy:makedatarequestsforchildren', CAP_ALLOW, $parentrole, $systemcontext);
        role_assign($parentrole, $parent->id, \context_user::instance($child->id));

        $this->setUser($parent);
        $this->assertTrue(api::can_create_data_request_for_user($child->id));
    }

    /**
     * Test that a user who has no capability to make any data requests for children cannot create data requests for any
     * other user.
     */
    public function test_require_can_create_data_request_for_user_no() {
        $this->resetAfterTest();

        $parent = $this->getDataGenerator()->create_user();
        $otheruser = $this->getDataGenerator()->create_user();

        $this->setUser($parent);
        $this->expectException('required_capability_exception');
        api::require_can_create_data_request_for_user($otheruser->id);
    }

    /**
     * Test that a user who has the capability to make any data requests for one other user cannot create data requests
     * for any other user.
     */
    public function test_require_can_create_data_request_for_user_some() {
        $this->resetAfterTest();

        $parent = $this->getDataGenerator()->create_user();
        $child = $this->getDataGenerator()->create_user();
        $otheruser = $this->getDataGenerator()->create_user();

        $systemcontext = \context_system::instance();
        $parentrole = $this->getDataGenerator()->create_role();
        assign_capability('tool/dataprivacy:makedatarequestsforchildren', CAP_ALLOW, $parentrole, $systemcontext);
        role_assign($parentrole, $parent->id, \context_user::instance($child->id));

        $this->setUser($parent);
        $this->expectException('required_capability_exception');
        api::require_can_create_data_request_for_user($otheruser->id);
    }

    /**
     * Test that a user who has the capability to make any data requests for one other user cannot create data requests
     * for any other user.
     */
    public function test_require_can_create_data_request_for_user_own_child() {
        $this->resetAfterTest();

        $parent = $this->getDataGenerator()->create_user();
        $child = $this->getDataGenerator()->create_user();

        $systemcontext = \context_system::instance();
        $parentrole = $this->getDataGenerator()->create_role();
        assign_capability('tool/dataprivacy:makedatarequestsforchildren', CAP_ALLOW, $parentrole, $systemcontext);
        role_assign($parentrole, $parent->id, \context_user::instance($child->id));

        $this->setUser($parent);
        $this->assertTrue(api::require_can_create_data_request_for_user($child->id));
    }

    /**
     * Test for api::can_download_data_request_for_user()
     */
    public function test_can_download_data_request_for_user() {
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();

        // Three victims.
        $victim1 = $generator->create_user();
        $victim2 = $generator->create_user();
        $victim3 = $generator->create_user();

        // Assign a user as victim 1's parent.
        $systemcontext = \context_system::instance();
        $parentrole = $generator->create_role();
        assign_capability('tool/dataprivacy:makedatarequestsforchildren', CAP_ALLOW, $parentrole, $systemcontext);
        $parent = $generator->create_user();
        role_assign($parentrole, $parent->id, \context_user::instance($victim1->id));

        // Assign another user as data access wonder woman.
        $wonderrole = $generator->create_role();
        assign_capability('tool/dataprivacy:downloadallrequests', CAP_ALLOW, $wonderrole, $systemcontext);
        $staff = $generator->create_user();
        role_assign($wonderrole, $staff->id, $systemcontext);

        // Finally, victim 3 has been naughty; stop them accessing their own data.
        $naughtyrole = $generator->create_role();
        assign_capability('tool/dataprivacy:downloadownrequest', CAP_PROHIBIT, $naughtyrole, $systemcontext);
        role_assign($naughtyrole, $victim3->id, $systemcontext);

        // Victims 1 and 2 can access their own data, regardless of who requested it.
        $this->assertTrue(api::can_download_data_request_for_user($victim1->id, $victim1->id, $victim1->id));
        $this->assertTrue(api::can_download_data_request_for_user($victim2->id, $staff->id, $victim2->id));

        // Victim 3 cannot access his own data.
        $this->assertFalse(api::can_download_data_request_for_user($victim3->id, $victim3->id, $victim3->id));

        // Victims 1 and 2 cannot access another victim's data.
        $this->assertFalse(api::can_download_data_request_for_user($victim2->id, $victim1->id, $victim1->id));
        $this->assertFalse(api::can_download_data_request_for_user($victim1->id, $staff->id, $victim2->id));

        // Staff can access everyone's data.
        $this->assertTrue(api::can_download_data_request_for_user($victim1->id, $victim1->id, $staff->id));
        $this->assertTrue(api::can_download_data_request_for_user($victim2->id, $staff->id, $staff->id));
        $this->assertTrue(api::can_download_data_request_for_user($victim3->id, $staff->id, $staff->id));

        // Parent can access victim 1's data only if they requested it.
        $this->assertTrue(api::can_download_data_request_for_user($victim1->id, $parent->id, $parent->id));
        $this->assertFalse(api::can_download_data_request_for_user($victim1->id, $staff->id, $parent->id));
        $this->assertFalse(api::can_download_data_request_for_user($victim2->id, $parent->id, $parent->id));
    }

    /**
     * Test for api::create_data_request()
     */
    public function test_create_data_request() {
        $this->resetAfterTest();

        $generator = new testing_data_generator();
        $user = $generator->create_user();
        $comment = 'sample comment';

        // Login as user.
        $this->setUser($user->id);

        // Test data request creation.
        $datarequest = api::create_data_request($user->id, api::DATAREQUEST_TYPE_EXPORT, $comment);
        $this->assertEquals($user->id, $datarequest->get('userid'));
        $this->assertEquals($user->id, $datarequest->get('requestedby'));
        $this->assertEquals(0, $datarequest->get('dpo'));
        $this->assertEquals(api::DATAREQUEST_TYPE_EXPORT, $datarequest->get('type'));
        $this->assertEquals(api::DATAREQUEST_STATUS_PENDING, $datarequest->get('status'));
        $this->assertEquals($comment, $datarequest->get('comments'));

        // Test adhoc task creation.
        $adhoctasks = manager::get_adhoc_tasks(initiate_data_request_task::class);
        $this->assertCount(1, $adhoctasks);
    }

    /**
     * Test for api::create_data_request() made by DPO.
     */
    public function test_create_data_request_by_dpo() {
        global $USER;

        $this->resetAfterTest();

        $generator = new testing_data_generator();
        $user = $generator->create_user();
        $comment = 'sample comment';

        // Login as DPO (Admin is DPO by default).
        $this->setAdminUser();

        // Test data request creation.
        $datarequest = api::create_data_request($user->id, api::DATAREQUEST_TYPE_EXPORT, $comment);
        $this->assertEquals($user->id, $datarequest->get('userid'));
        $this->assertEquals($USER->id, $datarequest->get('requestedby'));
        $this->assertEquals($USER->id, $datarequest->get('dpo'));
        $this->assertEquals(api::DATAREQUEST_TYPE_EXPORT, $datarequest->get('type'));
        $this->assertEquals(api::DATAREQUEST_STATUS_PENDING, $datarequest->get('status'));
        $this->assertEquals($comment, $datarequest->get('comments'));

        // Test adhoc task creation.
        $adhoctasks = manager::get_adhoc_tasks(initiate_data_request_task::class);
        $this->assertCount(1, $adhoctasks);
    }

    /**
     * Test for api::create_data_request() made by a parent.
     */
    public function test_create_data_request_by_parent() {
        global $DB;

        $this->resetAfterTest();

        $generator = new testing_data_generator();
        $user = $generator->create_user();
        $parent = $generator->create_user();
        $comment = 'sample comment';

        // Get the teacher role pretend it's the parent roles ;).
        $systemcontext = context_system::instance();
        $usercontext = context_user::instance($user->id);
        $parentroleid = $DB->get_field('role', 'id', array('shortname' => 'teacher'));
        // Give the manager role with the capability to manage data requests.
        assign_capability('tool/dataprivacy:makedatarequestsforchildren', CAP_ALLOW, $parentroleid, $systemcontext->id, true);
        // Assign the parent to user.
        role_assign($parentroleid, $parent->id, $usercontext->id);

        // Login as the user's parent.
        $this->setUser($parent);

        // Test data request creation.
        $datarequest = api::create_data_request($user->id, api::DATAREQUEST_TYPE_EXPORT, $comment);
        $this->assertEquals($user->id, $datarequest->get('userid'));
        $this->assertEquals($parent->id, $datarequest->get('requestedby'));
        $this->assertEquals(0, $datarequest->get('dpo'));
        $this->assertEquals(api::DATAREQUEST_TYPE_EXPORT, $datarequest->get('type'));
        $this->assertEquals(api::DATAREQUEST_STATUS_PENDING, $datarequest->get('status'));
        $this->assertEquals($comment, $datarequest->get('comments'));

        // Test adhoc task creation.
        $adhoctasks = manager::get_adhoc_tasks(initiate_data_request_task::class);
        $this->assertCount(1, $adhoctasks);
    }

    /**
     * Test for api::deny_data_request()
     */
    public function test_deny_data_request() {
        $this->resetAfterTest();

        $generator = new testing_data_generator();
        $user = $generator->create_user();
        $comment = 'sample comment';

        // Login as user.
        $this->setUser($user->id);

        // Test data request creation.
        $datarequest = api::create_data_request($user->id, api::DATAREQUEST_TYPE_EXPORT, $comment);

        // Login as the admin (default DPO when no one is set).
        $this->setAdminUser();

        // Make this ready for approval.
        api::update_request_status($datarequest->get('id'), api::DATAREQUEST_STATUS_AWAITING_APPROVAL);

        // Deny the data request.
        $result = api::deny_data_request($datarequest->get('id'));
        $this->assertTrue($result);
    }

    /**
     * Data provider for \tool_dataprivacy_api_testcase::test_get_data_requests().
     *
     * @return array
     */
    public function get_data_requests_provider() {
        $completeonly = [api::DATAREQUEST_STATUS_COMPLETE, api::DATAREQUEST_STATUS_DOWNLOAD_READY, api::DATAREQUEST_STATUS_DELETED];
        $completeandcancelled = array_merge($completeonly, [api::DATAREQUEST_STATUS_CANCELLED]);

        return [
            // Own data requests.
            ['user', false, $completeonly],
            // Non-DPO fetching all requets.
            ['user', true, $completeonly],
            // Admin fetching all completed and cancelled requests.
            ['dpo', true, $completeandcancelled],
            // Admin fetching all completed requests.
            ['dpo', true, $completeonly],
            // Guest fetching all requests.
            ['guest', true, $completeonly],
        ];
    }

    /**
     * Test for api::get_data_requests()
     *
     * @dataProvider get_data_requests_provider
     * @param string $usertype The type of the user logging in.
     * @param boolean $fetchall Whether to fetch all records.
     * @param int[] $statuses Status filters.
     */
    public function test_get_data_requests($usertype, $fetchall, $statuses) {
        $this->resetAfterTest();

        $generator = new testing_data_generator();
        $user1 = $generator->create_user();
        $user2 = $generator->create_user();
        $user3 = $generator->create_user();
        $user4 = $generator->create_user();
        $user5 = $generator->create_user();
        $users = [$user1, $user2, $user3, $user4, $user5];

        switch ($usertype) {
            case 'user':
                $loggeduser = $user1;
                break;
            case 'dpo':
                $loggeduser = get_admin();
                break;
            case 'guest':
                $loggeduser = guest_user();
                break;
        }

        $comment = 'Data %s request comment by user %d';
        $exportstring = helper::get_shortened_request_type_string(api::DATAREQUEST_TYPE_EXPORT);
        $deletionstring = helper::get_shortened_request_type_string(api::DATAREQUEST_TYPE_DELETE);
        // Make a data requests for the users.
        foreach ($users as $user) {
            $this->setUser($user);
            api::create_data_request($user->id, api::DATAREQUEST_TYPE_EXPORT, sprintf($comment, $exportstring, $user->id));
            api::create_data_request($user->id, api::DATAREQUEST_TYPE_EXPORT, sprintf($comment, $deletionstring, $user->id));
        }

        // Log in as the target user.
        $this->setUser($loggeduser);
        // Get records count based on the filters.
        $userid = $loggeduser->id;
        if ($fetchall) {
            $userid = 0;
        }
        $count = api::get_data_requests_count($userid);
        if (api::is_site_dpo($loggeduser->id)) {
            // DPOs should see all the requests.
            $this->assertEquals(count($users) * 2, $count);
        } else {
            if (empty($userid)) {
                // There should be no data requests for this user available.
                $this->assertEquals(0, $count);
            } else {
                // There should be only one (request with pending status).
                $this->assertEquals(2, $count);
            }
        }
        // Get data requests.
        $requests = api::get_data_requests($userid);
        // The number of requests should match the count.
        $this->assertCount($count, $requests);

        // Test filtering by status.
        if ($count && !empty($statuses)) {
            $filteredcount = api::get_data_requests_count($userid, $statuses);
            // There should be none as they are all pending.
            $this->assertEquals(0, $filteredcount);
            $filteredrequests = api::get_data_requests($userid, $statuses);
            $this->assertCount($filteredcount, $filteredrequests);

            $statuscounts = [];
            foreach ($statuses as $stat) {
                $statuscounts[$stat] = 0;
            }
            $numstatus = count($statuses);
            // Get all requests with status filter and update statuses, randomly.
            foreach ($requests as $request) {
                if (rand(0, 1)) {
                    continue;
                }

                if ($numstatus > 1) {
                    $index = rand(0, $numstatus - 1);
                    $status = $statuses[$index];
                } else {
                    $status = reset($statuses);
                }
                $statuscounts[$status]++;
                api::update_request_status($request->get('id'), $status);
            }
            $total = array_sum($statuscounts);
            $filteredcount = api::get_data_requests_count($userid, $statuses);
            $this->assertEquals($total, $filteredcount);
            $filteredrequests = api::get_data_requests($userid, $statuses);
            $this->assertCount($filteredcount, $filteredrequests);
            // Confirm the filtered requests match the status filter(s).
            foreach ($filteredrequests as $request) {
                $this->assertContains($request->get('status'), $statuses);
            }

            if ($numstatus > 1) {
                // Fetch by individual status to check the numbers match.
                foreach ($statuses as $status) {
                    $filteredcount = api::get_data_requests_count($userid, [$status]);
                    $this->assertEquals($statuscounts[$status], $filteredcount);
                    $filteredrequests = api::get_data_requests($userid, [$status]);
                    $this->assertCount($filteredcount, $filteredrequests);
                }
            }
        }
    }

    /**
     * Data provider for test_has_ongoing_request.
     */
    public function status_provider() {
        return [
            [api::DATAREQUEST_STATUS_PENDING, true],
            [api::DATAREQUEST_STATUS_PREPROCESSING, true],
            [api::DATAREQUEST_STATUS_AWAITING_APPROVAL, true],
            [api::DATAREQUEST_STATUS_APPROVED, true],
            [api::DATAREQUEST_STATUS_PROCESSING, true],
            [api::DATAREQUEST_STATUS_COMPLETE, false],
            [api::DATAREQUEST_STATUS_CANCELLED, false],
            [api::DATAREQUEST_STATUS_REJECTED, false],
            [api::DATAREQUEST_STATUS_DOWNLOAD_READY, false],
            [api::DATAREQUEST_STATUS_EXPIRED, false],
            [api::DATAREQUEST_STATUS_DELETED, false],
        ];
    }

    /**
     * Test for api::has_ongoing_request()
     *
     * @dataProvider status_provider
     * @param int $status The request status.
     * @param bool $expected The expected result.
     */
    public function test_has_ongoing_request($status, $expected) {
        $this->resetAfterTest();

        $generator = new testing_data_generator();
        $user1 = $generator->create_user();

        // Make a data request as user 1.
        $this->setUser($user1);
        $request = api::create_data_request($user1->id, api::DATAREQUEST_TYPE_EXPORT);
        // Set the status.
        api::update_request_status($request->get('id'), $status);

        // Check if this request is ongoing.
        $result = api::has_ongoing_request($user1->id, api::DATAREQUEST_TYPE_EXPORT);
        $this->assertEquals($expected, $result);
    }

    /**
     * Test for api::is_active()
     *
     * @dataProvider status_provider
     * @param int $status The request status
     * @param bool $expected The expected result
     */
    public function test_is_active($status, $expected) {
        // Check if this request is ongoing.
        $result = api::is_active($status);
        $this->assertEquals($expected, $result);
    }

    /**
     * Test for api::is_site_dpo()
     */
    public function test_is_site_dpo() {
        global $DB;

        $this->resetAfterTest();

        // No configured site DPOs yet.
        $admin = get_admin();
        $this->assertTrue(api::is_site_dpo($admin->id));

        $generator = new testing_data_generator();
        $dpo = $generator->create_user();
        $nondpo = $generator->create_user();

        $context = context_system::instance();

        // Manager role.
        $managerroleid = $DB->get_field('role', 'id', array('shortname' => 'manager'));
        // Give the manager role with the capability to manage data requests.
        assign_capability('tool/dataprivacy:managedatarequests', CAP_ALLOW, $managerroleid, $context->id, true);
        // Assign u1 as a manager.
        role_assign($managerroleid, $dpo->id, $context->id);

        // Map only the manager role to the DPO role.
        set_config('dporoles', $managerroleid, 'tool_dataprivacy');

        // User is a DPO.
        $this->assertTrue(api::is_site_dpo($dpo->id));
        // User is not a DPO.
        $this->assertFalse(api::is_site_dpo($nondpo->id));
    }

    /**
     * Data provider function for test_notify_dpo
     *
     * @return array
     */
    public function notify_dpo_provider() {
        return [
            [false, api::DATAREQUEST_TYPE_EXPORT, 'requesttypeexport', 'Export my user data'],
            [false, api::DATAREQUEST_TYPE_DELETE, 'requesttypedelete', 'Delete my user data'],
            [false, api::DATAREQUEST_TYPE_OTHERS, 'requesttypeothers', 'Nothing. Just wanna say hi'],
            [true, api::DATAREQUEST_TYPE_EXPORT, 'requesttypeexport', 'Admin export data of another user'],
        ];
    }

    /**
     * Test for api::notify_dpo()
     *
     * @dataProvider notify_dpo_provider
     * @param bool $byadmin Whether the admin requests data on behalf of the user
     * @param int $type The request type
     * @param string $typestringid The request lang string identifier
     * @param string $comments The requestor's message to the DPO.
     */
    public function test_notify_dpo($byadmin, $type, $typestringid, $comments) {
        $this->resetAfterTest();

        $generator = new testing_data_generator();
        $user1 = $generator->create_user();
        // Let's just use admin as DPO (It's the default if not set).
        $dpo = get_admin();
        if ($byadmin) {
            $this->setAdminUser();
            $requestedby = $dpo;
        } else {
            $this->setUser($user1);
            $requestedby = $user1;
        }

        // Make a data request for user 1.
        $request = api::create_data_request($user1->id, $type, $comments);

        $sink = $this->redirectMessages();
        $messageid = api::notify_dpo($dpo, $request);
        $this->assertNotFalse($messageid);
        $messages = $sink->get_messages();
        $this->assertCount(1, $messages);
        $message = reset($messages);

        // Check some of the message properties.
        $this->assertEquals($requestedby->id, $message->useridfrom);
        $this->assertEquals($dpo->id, $message->useridto);
        $typestring = get_string($typestringid, 'tool_dataprivacy');
        $subject = get_string('datarequestemailsubject', 'tool_dataprivacy', $typestring);
        $this->assertEquals($subject, $message->subject);
        $this->assertEquals('tool_dataprivacy', $message->component);
        $this->assertEquals('contactdataprotectionofficer', $message->eventtype);
        $this->assertContains(fullname($dpo), $message->fullmessage);
        $this->assertContains(fullname($user1), $message->fullmessage);
    }

    /**
     * Test data purposes CRUD actions.
     *
     * @return null
     */
    public function test_purpose_crud() {
        $this->resetAfterTest();

        $this->setAdminUser();

        // Add.
        $purpose = api::create_purpose((object)[
            'name' => 'bbb',
            'description' => '<b>yeah</b>',
            'descriptionformat' => 1,
            'retentionperiod' => 'PT1M',
            'lawfulbases' => 'gdpr_art_6_1_a,gdpr_art_6_1_c,gdpr_art_6_1_e'
        ]);
        $this->assertInstanceOf('\tool_dataprivacy\purpose', $purpose);
        $this->assertEquals('bbb', $purpose->get('name'));
        $this->assertEquals('PT1M', $purpose->get('retentionperiod'));
        $this->assertEquals('gdpr_art_6_1_a,gdpr_art_6_1_c,gdpr_art_6_1_e', $purpose->get('lawfulbases'));

        // Update.
        $purpose->set('retentionperiod', 'PT2M');
        $purpose = api::update_purpose($purpose->to_record());
        $this->assertEquals('PT2M', $purpose->get('retentionperiod'));

        // Retrieve.
        $purpose = api::create_purpose((object)['name' => 'aaa', 'retentionperiod' => 'PT1M', 'lawfulbases' => 'gdpr_art_6_1_a']);
        $purposes = api::get_purposes();
        $this->assertCount(2, $purposes);
        $this->assertEquals('aaa', $purposes[0]->get('name'));
        $this->assertEquals('bbb', $purposes[1]->get('name'));

        // Delete.
        api::delete_purpose($purposes[0]->get('id'));
        $this->assertCount(1, api::get_purposes());
        api::delete_purpose($purposes[1]->get('id'));
        $this->assertCount(0, api::get_purposes());
    }

    /**
     * Test data categories CRUD actions.
     *
     * @return null
     */
    public function test_category_crud() {
        $this->resetAfterTest();

        $this->setAdminUser();

        // Add.
        $category = api::create_category((object)[
            'name' => 'bbb',
            'description' => '<b>yeah</b>',
            'descriptionformat' => 1
        ]);
        $this->assertInstanceOf('\tool_dataprivacy\category', $category);
        $this->assertEquals('bbb', $category->get('name'));

        // Update.
        $category->set('name', 'bcd');
        $category = api::update_category($category->to_record());
        $this->assertEquals('bcd', $category->get('name'));

        // Retrieve.
        $category = api::create_category((object)['name' => 'aaa']);
        $categories = api::get_categories();
        $this->assertCount(2, $categories);
        $this->assertEquals('aaa', $categories[0]->get('name'));
        $this->assertEquals('bcd', $categories[1]->get('name'));

        // Delete.
        api::delete_category($categories[0]->get('id'));
        $this->assertCount(1, api::get_categories());
        api::delete_category($categories[1]->get('id'));
        $this->assertCount(0, api::get_categories());
    }

    /**
     * Test context instances.
     *
     * @return null
     */
    public function test_context_instances() {
        global $DB;

        $this->resetAfterTest();

        $this->setAdminUser();

        list($purposes, $categories, $courses, $modules) = $this->add_purposes_and_categories();

        $coursecontext1 = \context_course::instance($courses[0]->id);
        $coursecontext2 = \context_course::instance($courses[1]->id);

        $record1 = (object)['contextid' => $coursecontext1->id, 'purposeid' => $purposes[0]->get('id'),
            'categoryid' => $categories[0]->get('id')];
        $contextinstance1 = api::set_context_instance($record1);

        $record2 = (object)['contextid' => $coursecontext2->id, 'purposeid' => $purposes[1]->get('id'),
            'categoryid' => $categories[1]->get('id')];
        $contextinstance2 = api::set_context_instance($record2);

        $this->assertCount(2, $DB->get_records('tool_dataprivacy_ctxinstance'));

        api::unset_context_instance($contextinstance1);
        $this->assertCount(1, $DB->get_records('tool_dataprivacy_ctxinstance'));

        $update = (object)['id' => $contextinstance2->get('id'), 'contextid' => $coursecontext2->id,
            'purposeid' => $purposes[0]->get('id'), 'categoryid' => $categories[0]->get('id')];
        $contextinstance2 = api::set_context_instance($update);
        $this->assertCount(1, $DB->get_records('tool_dataprivacy_ctxinstance'));
    }

    /**
     * Test contextlevel.
     *
     * @return null
     */
    public function test_contextlevel() {
        global $DB;

        $this->resetAfterTest();

        $this->setAdminUser();
        list($purposes, $categories, $courses, $modules) = $this->add_purposes_and_categories();

        $record = (object)[
            'purposeid' => $purposes[0]->get('id'),
            'categoryid' => $categories[0]->get('id'),
            'contextlevel' => CONTEXT_SYSTEM,
        ];
        $contextlevel = api::set_contextlevel($record);
        $this->assertInstanceOf('\tool_dataprivacy\contextlevel', $contextlevel);
        $this->assertEquals($record->contextlevel, $contextlevel->get('contextlevel'));
        $this->assertEquals($record->purposeid, $contextlevel->get('purposeid'));
        $this->assertEquals($record->categoryid, $contextlevel->get('categoryid'));

        // Now update it.
        $record->purposeid = $purposes[1]->get('id');
        $contextlevel = api::set_contextlevel($record);
        $this->assertEquals($record->contextlevel, $contextlevel->get('contextlevel'));
        $this->assertEquals($record->purposeid, $contextlevel->get('purposeid'));
        $this->assertEquals(1, $DB->count_records('tool_dataprivacy_ctxlevel'));

        $record->contextlevel = CONTEXT_USER;
        $contextlevel = api::set_contextlevel($record);
        $this->assertEquals(2, $DB->count_records('tool_dataprivacy_ctxlevel'));
    }

    /**
     * Test effective context levels purpose and category defaults.
     *
     * @return null
     */
    public function test_effective_contextlevel_defaults() {
        $this->setAdminUser();

        $this->resetAfterTest();

        list($purposes, $categories, $courses, $modules) = $this->add_purposes_and_categories();

        list($purposeid, $categoryid) = data_registry::get_effective_default_contextlevel_purpose_and_category(CONTEXT_SYSTEM);
        $this->assertEquals(false, $purposeid);
        $this->assertEquals(false, $categoryid);

        list($purposevar, $categoryvar) = data_registry::var_names_from_context(
            \context_helper::get_class_for_level(CONTEXT_SYSTEM)
        );
        set_config($purposevar, $purposes[0]->get('id'), 'tool_dataprivacy');

        list($purposeid, $categoryid) = data_registry::get_effective_default_contextlevel_purpose_and_category(CONTEXT_SYSTEM);
        $this->assertEquals($purposes[0]->get('id'), $purposeid);
        $this->assertEquals(false, $categoryid);

        // Course inherits from system if not defined.
        list($purposeid, $categoryid) = data_registry::get_effective_default_contextlevel_purpose_and_category(CONTEXT_COURSE);
        $this->assertEquals($purposes[0]->get('id'), $purposeid);
        $this->assertEquals(false, $categoryid);

        // Course defined values should have preference.
        list($purposevar, $categoryvar) = data_registry::var_names_from_context(
            \context_helper::get_class_for_level(CONTEXT_COURSE)
        );
        set_config($purposevar, $purposes[1]->get('id'), 'tool_dataprivacy');
        set_config($categoryvar, $categories[0]->get('id'), 'tool_dataprivacy');

        list($purposeid, $categoryid) = data_registry::get_effective_default_contextlevel_purpose_and_category(CONTEXT_COURSE);
        $this->assertEquals($purposes[1]->get('id'), $purposeid);
        $this->assertEquals($categories[0]->get('id'), $categoryid);

        // Context level defaults are also allowed to be set to 'inherit'.
        set_config($purposevar, context_instance::INHERIT, 'tool_dataprivacy');

        list($purposeid, $categoryid) = data_registry::get_effective_default_contextlevel_purpose_and_category(CONTEXT_COURSE);
        $this->assertEquals($purposes[0]->get('id'), $purposeid);
        $this->assertEquals($categories[0]->get('id'), $categoryid);

        list($purposeid, $categoryid) = data_registry::get_effective_default_contextlevel_purpose_and_category(CONTEXT_MODULE);
        $this->assertEquals($purposes[0]->get('id'), $purposeid);
        $this->assertEquals($categories[0]->get('id'), $categoryid);
    }

    public function test_get_effective_contextlevel_category() {
        // Before setup, get_effective_contextlevel_purpose will return false.
        $this->assertFalse(api::get_effective_contextlevel_category(CONTEXT_SYSTEM));
    }

    /**
     * Test effective contextlevel return.
     */
    public function test_effective_contextlevel() {
        $this->setAdminUser();

        $this->resetAfterTest();

        // Before setup, get_effective_contextlevel_purpose will return false.
        $this->assertFalse(api::get_effective_contextlevel_purpose(CONTEXT_SYSTEM));

        list($purposes, $categories, $courses, $modules) = $this->add_purposes_and_categories();

        // Set the system context level to purpose 1.
        $record = (object)[
            'contextlevel' => CONTEXT_SYSTEM,
            'purposeid' => $purposes[1]->get('id'),
            'categoryid' => $categories[1]->get('id'),
        ];
        api::set_contextlevel($record);

        $purpose = api::get_effective_contextlevel_purpose(CONTEXT_SYSTEM);
        $this->assertEquals($purposes[1]->get('id'), $purpose->get('id'));

        // Value 'not set' will get the default value for the context level. For context level defaults
        // both 'not set' and 'inherit' result in inherit, so the parent context (system) default
        // will be retrieved.
        $purpose = api::get_effective_contextlevel_purpose(CONTEXT_USER);
        $this->assertEquals($purposes[1]->get('id'), $purpose->get('id'));

        // The behaviour forcing an inherit from context system should result in the same effective
        // purpose.
        $record->purposeid = context_instance::INHERIT;
        $record->contextlevel = CONTEXT_USER;
        api::set_contextlevel($record);
        $purpose = api::get_effective_contextlevel_purpose(CONTEXT_USER);
        $this->assertEquals($purposes[1]->get('id'), $purpose->get('id'));

        $record->purposeid = $purposes[2]->get('id');
        $record->contextlevel = CONTEXT_USER;
        api::set_contextlevel($record);

        $purpose = api::get_effective_contextlevel_purpose(CONTEXT_USER);
        $this->assertEquals($purposes[2]->get('id'), $purpose->get('id'));

        // Only system and user allowed.
        $this->expectException(coding_exception::class);
        $record->contextlevel = CONTEXT_COURSE;
        $record->purposeid = $purposes[1]->get('id');
        api::set_contextlevel($record);
    }

    /**
     * Test effective context purposes and categories.
     *
     * @return null
     */
    public function test_effective_context() {
        $this->resetAfterTest();

        $this->setAdminUser();

        list($purposes, $categories, $courses, $modules) = $this->add_purposes_and_categories();

        // Define system defaults (all context levels below will inherit).
        list($purposevar, $categoryvar) = data_registry::var_names_from_context(
            \context_helper::get_class_for_level(CONTEXT_SYSTEM)
        );
        set_config($purposevar, $purposes[0]->get('id'), 'tool_dataprivacy');
        set_config($categoryvar, $categories[0]->get('id'), 'tool_dataprivacy');

        // Define course defaults.
        list($purposevar, $categoryvar) = data_registry::var_names_from_context(
            \context_helper::get_class_for_level(CONTEXT_COURSE)
        );
        set_config($purposevar, $purposes[1]->get('id'), 'tool_dataprivacy');
        set_config($categoryvar, $categories[1]->get('id'), 'tool_dataprivacy');

        $course0context = \context_course::instance($courses[0]->id);
        $course1context = \context_course::instance($courses[1]->id);
        $mod0context = \context_module::instance($modules[0]->cmid);
        $mod1context = \context_module::instance($modules[1]->cmid);

        // Set course instance values.
        $record = (object)[
            'contextid' => $course0context->id,
            'purposeid' => $purposes[1]->get('id'),
            'categoryid' => $categories[2]->get('id'),
        ];
        api::set_context_instance($record);
        $category = api::get_effective_context_category($course0context);
        $this->assertEquals($record->categoryid, $category->get('id'));

        // Module instances get the context level default if nothing specified.
        $category = api::get_effective_context_category($mod0context);
        $this->assertEquals($categories[1]->get('id'), $category->get('id'));

        // Module instances get the parent context category if they inherit.
        $record->contextid = $mod0context->id;
        $record->categoryid = context_instance::INHERIT;
        api::set_context_instance($record);
        $category = api::get_effective_context_category($mod0context);
        $this->assertEquals($categories[2]->get('id'), $category->get('id'));

        // The $forcedvalue param allows us to override the actual value (method php-docs for more info).
        $category = api::get_effective_context_category($mod0context, $categories[1]->get('id'));
        $this->assertEquals($categories[1]->get('id'), $category->get('id'));
        $category = api::get_effective_context_category($mod0context, $categories[0]->get('id'));
        $this->assertEquals($categories[0]->get('id'), $category->get('id'));

        // Module instances get the parent context category if they inherit; in
        // this case the parent context category is not set so it should use the
        // context level default (see 'Define course defaults' above).
        $record->contextid = $mod1context->id;
        $record->categoryid = context_instance::INHERIT;
        api::set_context_instance($record);
        $category = api::get_effective_context_category($mod1context);
        $this->assertEquals($categories[1]->get('id'), $category->get('id'));

        // User instances use the value set at user context level instead of the user default.

        // User defaults to cat 0 and user context level to 1.
        list($purposevar, $categoryvar) = data_registry::var_names_from_context(
            \context_helper::get_class_for_level(CONTEXT_USER)
        );
        set_config($purposevar, $purposes[0]->get('id'), 'tool_dataprivacy');
        set_config($categoryvar, $categories[0]->get('id'), 'tool_dataprivacy');
        $usercontextlevel = (object)[
            'contextlevel' => CONTEXT_USER,
            'purposeid' => $purposes[1]->get('id'),
            'categoryid' => $categories[1]->get('id'),
        ];
        api::set_contextlevel($usercontextlevel);

        $newuser = $this->getDataGenerator()->create_user();
        $usercontext = \context_user::instance($newuser->id);
        $category = api::get_effective_context_category($usercontext);
        $this->assertEquals($categories[1]->get('id'), $category->get('id'));
    }

    /**
     * Creates test purposes and categories.
     *
     * @return null
     */
    protected function add_purposes_and_categories() {
        $this->resetAfterTest();

        $purpose1 = api::create_purpose((object)['name' => 'p1', 'retentionperiod' => 'PT1H', 'lawfulbases' => 'gdpr_art_6_1_a']);
        $purpose2 = api::create_purpose((object)['name' => 'p2', 'retentionperiod' => 'PT2H', 'lawfulbases' => 'gdpr_art_6_1_b']);
        $purpose3 = api::create_purpose((object)['name' => 'p3', 'retentionperiod' => 'PT3H', 'lawfulbases' => 'gdpr_art_6_1_c']);

        $cat1 = api::create_category((object)['name' => 'a']);
        $cat2 = api::create_category((object)['name' => 'b']);
        $cat3 = api::create_category((object)['name' => 'c']);

        $course1 = $this->getDataGenerator()->create_course();
        $course2 = $this->getDataGenerator()->create_course();

        $module1 = $this->getDataGenerator()->create_module('resource', array('course' => $course1));
        $module2 = $this->getDataGenerator()->create_module('resource', array('course' => $course2));

        return [
            [$purpose1, $purpose2, $purpose3],
            [$cat1, $cat2, $cat3],
            [$course1, $course2],
            [$module1, $module2]
        ];
    }

    /**
     * Test that delete requests filter out protected purpose contexts.
     */
    public function test_add_request_contexts_with_status_delete() {
        $this->resetAfterTest();

        $data = $this->setup_test_add_request_contexts_with_status(api::DATAREQUEST_TYPE_DELETE);
        $contextids = $data->list->get_contextids();

        $this->assertCount(1, $contextids);
        $this->assertEquals($data->contexts->unprotected, $contextids);
    }

    /**
     * Test that export requests don't filter out protected purpose contexts.
     */
    public function test_add_request_contexts_with_status_export() {
        $this->resetAfterTest();

        $data = $this->setup_test_add_request_contexts_with_status(api::DATAREQUEST_TYPE_EXPORT);
        $contextids = $data->list->get_contextids();

        $this->assertCount(2, $contextids);
        $this->assertEquals($data->contexts->used, $contextids, '', 0.0, 10, true);
    }

    /**
     * Test that delete requests do not filter out protected purpose contexts if they are already expired.
     */
    public function test_add_request_contexts_with_status_delete_course_expired_protected() {
        global $DB;

        $this->resetAfterTest();

        $purposes = $this->setup_basics('PT1H', 'PT1H', 'PT1H');
        $purposes->course->set('protected', 1)->save();

        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course(['startdate' => time() - YEARSECS, 'enddate' => time() - YEARSECS]);
        $coursecontext = \context_course::instance($course->id);

        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');

        $collection = new \core_privacy\local\request\contextlist_collection($user->id);
        $contextlist = new \core_privacy\local\request\contextlist();
        $contextlist->set_component('tool_dataprivacy');
        $contextlist->add_from_sql('SELECT id FROM {context} WHERE id IN(:ctx1)', ['ctx1' => $coursecontext->id]);
        $collection->add_contextlist($contextlist);

        $request = api::create_data_request($user->id, api::DATAREQUEST_TYPE_DELETE);

        $purposes->course->set('protected', 1)->save();
        api::add_request_contexts_with_status($collection, $request->get('id'), contextlist_context::STATUS_APPROVED);

        $requests = contextlist_context::get_records();
        $this->assertCount(1, $requests);
    }

    /**
     * Test that delete requests does filter out protected purpose contexts which are not expired.
     */
    public function test_add_request_contexts_with_status_delete_course_unexpired_protected() {
        global $DB;

        $this->resetAfterTest();

        $purposes = $this->setup_basics('PT1H', 'PT1H', 'P1Y');
        $purposes->course->set('protected', 1)->save();

        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course(['startdate' => time() - YEARSECS, 'enddate' => time()]);
        $coursecontext = \context_course::instance($course->id);

        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');

        $collection = new \core_privacy\local\request\contextlist_collection($user->id);
        $contextlist = new \core_privacy\local\request\contextlist();
        $contextlist->set_component('tool_dataprivacy');
        $contextlist->add_from_sql('SELECT id FROM {context} WHERE id IN(:ctx1)', ['ctx1' => $coursecontext->id]);
        $collection->add_contextlist($contextlist);

        $request = api::create_data_request($user->id, api::DATAREQUEST_TYPE_DELETE);

        $purposes->course->set('protected', 1)->save();
        api::add_request_contexts_with_status($collection, $request->get('id'), contextlist_context::STATUS_APPROVED);

        $requests = contextlist_context::get_records();
        $this->assertCount(0, $requests);
    }

    /**
     * Test that delete requests do not filter out unexpired contexts if they are not protected.
     */
    public function test_add_request_contexts_with_status_delete_course_unexpired_unprotected() {
        global $DB;

        $this->resetAfterTest();

        $purposes = $this->setup_basics('PT1H', 'PT1H', 'P1Y');
        $purposes->course->set('protected', 1)->save();

        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course(['startdate' => time() - YEARSECS, 'enddate' => time()]);
        $coursecontext = \context_course::instance($course->id);

        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');

        $collection = new \core_privacy\local\request\contextlist_collection($user->id);
        $contextlist = new \core_privacy\local\request\contextlist();
        $contextlist->set_component('tool_dataprivacy');
        $contextlist->add_from_sql('SELECT id FROM {context} WHERE id IN(:ctx1)', ['ctx1' => $coursecontext->id]);
        $collection->add_contextlist($contextlist);

        $request = api::create_data_request($user->id, api::DATAREQUEST_TYPE_DELETE);

        $purposes->course->set('protected', 0)->save();
        api::add_request_contexts_with_status($collection, $request->get('id'), contextlist_context::STATUS_APPROVED);

        $requests = contextlist_context::get_records();
        $this->assertCount(1, $requests);
    }

    /**
     * Test that delete requests do not filter out protected purpose contexts if they are already expired.
     */
    public function test_get_approved_contextlist_collection_for_request_delete_course_expired_protected() {
        $this->resetAfterTest();

        $purposes = $this->setup_basics('PT1H', 'PT1H', 'PT1H');
        $purposes->course->set('protected', 1)->save();

        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course(['startdate' => time() - YEARSECS, 'enddate' => time() - YEARSECS]);
        $coursecontext = \context_course::instance($course->id);

        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');

        // Create the request, with its contextlist and context.
        $request = api::create_data_request($user->id, api::DATAREQUEST_TYPE_DELETE);
        $contextlist = new \tool_dataprivacy\contextlist(0, (object) ['component' => 'tool_dataprivacy']);
        $contextlist->save();

        $clcontext = new \tool_dataprivacy\contextlist_context(0, (object) [
                'contextid' => $coursecontext->id,
                'status' => contextlist_context::STATUS_APPROVED,
                'contextlistid' => $contextlist->get('id'),
            ]);
        $clcontext->save();

        $rcl = new \tool_dataprivacy\request_contextlist(0, (object) [
                'requestid' => $request->get('id'),
                'contextlistid' => $contextlist->get('id'),
            ]);
        $rcl->save();

        $purposes->course->set('protected', 1)->save();
        $collection = api::get_approved_contextlist_collection_for_request($request);

        $this->assertCount(1, $collection);

        $list = $collection->get_contextlist_for_component('tool_dataprivacy');
        $this->assertCount(1, $list);
    }

    /**
     * Test that delete requests does filter out protected purpose contexts which are not expired.
     */
    public function test_get_approved_contextlist_collection_for_request_delete_course_unexpired_protected() {
        $this->resetAfterTest();

        $purposes = $this->setup_basics('PT1H', 'PT1H', 'P1Y');
        $purposes->course->set('protected', 1)->save();

        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course(['startdate' => time() - YEARSECS, 'enddate' => time()]);
        $coursecontext = \context_course::instance($course->id);

        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');

        // Create the request, with its contextlist and context.
        $request = api::create_data_request($user->id, api::DATAREQUEST_TYPE_DELETE);
        $contextlist = new \tool_dataprivacy\contextlist(0, (object) ['component' => 'tool_dataprivacy']);
        $contextlist->save();

        $clcontext = new \tool_dataprivacy\contextlist_context(0, (object) [
                'contextid' => $coursecontext->id,
                'status' => contextlist_context::STATUS_APPROVED,
                'contextlistid' => $contextlist->get('id'),
            ]);
        $clcontext->save();

        $rcl = new \tool_dataprivacy\request_contextlist(0, (object) [
                'requestid' => $request->get('id'),
                'contextlistid' => $contextlist->get('id'),
            ]);
        $rcl->save();

        $purposes->course->set('protected', 1)->save();
        $collection = api::get_approved_contextlist_collection_for_request($request);

        $this->assertCount(0, $collection);

        $list = $collection->get_contextlist_for_component('tool_dataprivacy');
        $this->assertEmpty($list);
    }

    /**
     * Test that delete requests do not filter out unexpired contexts if they are not protected.
     */
    public function test_get_approved_contextlist_collection_for_request_delete_course_unexpired_unprotected() {
        $this->resetAfterTest();

        $purposes = $this->setup_basics('PT1H', 'PT1H', 'P1Y');
        $purposes->course->set('protected', 1)->save();

        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course(['startdate' => time() - YEARSECS, 'enddate' => time()]);
        $coursecontext = \context_course::instance($course->id);

        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');

        // Create the request, with its contextlist and context.
        $request = api::create_data_request($user->id, api::DATAREQUEST_TYPE_DELETE);
        $contextlist = new \tool_dataprivacy\contextlist(0, (object) ['component' => 'tool_dataprivacy']);
        $contextlist->save();

        $clcontext = new \tool_dataprivacy\contextlist_context(0, (object) [
                'contextid' => $coursecontext->id,
                'status' => contextlist_context::STATUS_APPROVED,
                'contextlistid' => $contextlist->get('id'),
            ]);
        $clcontext->save();

        $rcl = new \tool_dataprivacy\request_contextlist(0, (object) [
                'requestid' => $request->get('id'),
                'contextlistid' => $contextlist->get('id'),
            ]);
        $rcl->save();

        $purposes->course->set('protected', 0)->save();
        $collection = api::get_approved_contextlist_collection_for_request($request);

        $this->assertCount(1, $collection);

        $list = $collection->get_contextlist_for_component('tool_dataprivacy');
        $this->assertCount(1, $list);
    }

    /**
     * Data provider for \tool_dataprivacy_api_testcase::test_set_context_defaults
     */
    public function set_context_defaults_provider() {
        $contextlevels = [
            [CONTEXT_COURSECAT],
            [CONTEXT_COURSE],
            [CONTEXT_MODULE],
            [CONTEXT_BLOCK],
        ];
        $paramsets = [
            [true, true, false, false], // Inherit category and purpose, Not for activity, Don't override.
            [true, false, false, false], // Inherit category but not purpose, Not for activity, Don't override.
            [false, true, false, false], // Inherit purpose but not category, Not for activity, Don't override.
            [false, false, false, false], // Don't inherit both category and purpose, Not for activity, Don't override.
            [false, false, false, true], // Don't inherit both category and purpose, Not for activity, Override instances.
        ];
        $data = [];
        foreach ($contextlevels as $level) {
            foreach ($paramsets as $set) {
                $data[] = array_merge($level, $set);
            }
            if ($level == CONTEXT_MODULE) {
                // Add a combination where defaults for activity is being set.
                $data[] = [CONTEXT_MODULE, false, false, true, false];
                $data[] = [CONTEXT_MODULE, false, false, true, true];
            }
        }
        return $data;
    }

    /**
     * Test for \tool_dataprivacy\api::set_context_defaults()
     *
     * @dataProvider set_context_defaults_provider
     * @param int $contextlevel The context level
     * @param bool $inheritcategory Whether to set category value as INHERIT.
     * @param bool $inheritpurpose Whether to set purpose value as INHERIT.
     * @param bool $foractivity Whether to set defaults for an activity.
     * @param bool $override Whether to override instances.
     */
    public function test_set_context_defaults($contextlevel, $inheritcategory, $inheritpurpose, $foractivity, $override) {
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();

        // Generate course cat, course, block, assignment, forum instances.
        $coursecat = $generator->create_category();
        $course = $generator->create_course(['category' => $coursecat->id]);
        $block = $generator->create_block('online_users');
        $assign = $generator->create_module('assign', ['course' => $course->id]);
        $forum = $generator->create_module('forum', ['course' => $course->id]);

        $coursecatcontext = context_coursecat::instance($coursecat->id);
        $coursecontext = context_course::instance($course->id);
        $blockcontext = context_block::instance($block->id);

        list($course, $assigncm) = get_course_and_cm_from_instance($assign->id, 'assign');
        list($course, $forumcm) = get_course_and_cm_from_instance($forum->id, 'forum');
        $assigncontext = context_module::instance($assigncm->id);
        $forumcontext = context_module::instance($forumcm->id);

        // Generate purposes and categories.
        $category1 = api::create_category((object)['name' => 'Test category 1']);
        $category2 = api::create_category((object)['name' => 'Test category 2']);
        $purpose1 = api::create_purpose((object)[
            'name' => 'Test purpose 1', 'retentionperiod' => 'PT1M', 'lawfulbases' => 'gdpr_art_6_1_a'
        ]);
        $purpose2 = api::create_purpose((object)[
            'name' => 'Test purpose 2', 'retentionperiod' => 'PT1M', 'lawfulbases' => 'gdpr_art_6_1_a'
        ]);

        // Assign purposes and categories to contexts.
        $coursecatctxinstance = api::set_context_instance((object) [
            'contextid' => $coursecatcontext->id,
            'purposeid' => $purpose1->get('id'),
            'categoryid' => $category1->get('id'),
        ]);
        $coursectxinstance = api::set_context_instance((object) [
            'contextid' => $coursecontext->id,
            'purposeid' => $purpose1->get('id'),
            'categoryid' => $category1->get('id'),
        ]);
        $blockctxinstance = api::set_context_instance((object) [
            'contextid' => $blockcontext->id,
            'purposeid' => $purpose1->get('id'),
            'categoryid' => $category1->get('id'),
        ]);
        $assignctxinstance = api::set_context_instance((object) [
            'contextid' => $assigncontext->id,
            'purposeid' => $purpose1->get('id'),
            'categoryid' => $category1->get('id'),
        ]);
        $forumctxinstance = api::set_context_instance((object) [
            'contextid' => $forumcontext->id,
            'purposeid' => $purpose1->get('id'),
            'categoryid' => $category1->get('id'),
        ]);

        $categoryid = $inheritcategory ? context_instance::INHERIT : $category2->get('id');
        $purposeid = $inheritpurpose ? context_instance::INHERIT : $purpose2->get('id');
        $activity = '';
        if ($contextlevel == CONTEXT_MODULE && $foractivity) {
            $activity = 'assign';
        }
        $result = api::set_context_defaults($contextlevel, $categoryid, $purposeid, $activity, $override);
        $this->assertTrue($result);

        $targetctxinstance = false;
        switch ($contextlevel) {
            case CONTEXT_COURSECAT:
                $targetctxinstance = $coursecatctxinstance;
                break;
            case CONTEXT_COURSE:
                $targetctxinstance = $coursectxinstance;
                break;
            case CONTEXT_MODULE:
                $targetctxinstance = $assignctxinstance;
                break;
            case CONTEXT_BLOCK:
                $targetctxinstance = $blockctxinstance;
                break;
        }
        $this->assertNotFalse($targetctxinstance);

        // Check the context instances.
        $instanceexists = context_instance::record_exists($targetctxinstance->get('id'));
        if ($override) {
            // If overridden, context instances on this context level would have been deleted.
            $this->assertFalse($instanceexists);

            // Check forum context instance.
            $forumctxexists = context_instance::record_exists($forumctxinstance->get('id'));
            if ($contextlevel != CONTEXT_MODULE || $foractivity) {
                // The forum context instance won't be affected in this test if:
                // - The overridden defaults are not for context modules.
                // - Only the defaults for assign have been set.
                $this->assertTrue($forumctxexists);
            } else {
                // If we're overriding for the whole course module context level,
                // then this forum context instance will be deleted as well.
                $this->assertFalse($forumctxexists);
            }
        } else {
            // Otherwise, the context instance record remains.
            $this->assertTrue($instanceexists);
        }

        // Check defaults.
        list($defaultpurpose, $defaultcategory) = data_registry::get_defaults($contextlevel, $activity);
        if (!$inheritpurpose) {
            $this->assertEquals($purposeid, $defaultpurpose);
        }
        if (!$inheritcategory) {
            $this->assertEquals($categoryid, $defaultcategory);
        }
    }

    /**
     * Perform setup for the test_add_request_contexts_with_status_xxxxx tests.
     *
     * @param       int $type The type of request to create
     * @return      \stdClass
     */
    protected function setup_test_add_request_contexts_with_status($type) {
        $this->resetAfterTest();

        $this->setAdminUser();

        // User under test.
        $s1 = $this->getDataGenerator()->create_user();

        // Create three sample contexts.
        // 1 which should not be returned; and
        // 1 which will be returned and is not protected; and
        // 1 which will be returned and is protected.

        $c1 = $this->getDataGenerator()->create_course();
        $c2 = $this->getDataGenerator()->create_course();
        $c3 = $this->getDataGenerator()->create_course();

        $ctx1 = \context_course::instance($c1->id);
        $ctx2 = \context_course::instance($c2->id);
        $ctx3 = \context_course::instance($c3->id);

        $unprotected = api::create_purpose((object)[
            'name' => 'Unprotected', 'retentionperiod' => 'PT1M', 'lawfulbases' => 'gdpr_art_6_1_a']);
        $protected = api::create_purpose((object) [
            'name' => 'Protected', 'retentionperiod' => 'PT1M', 'lawfulbases' => 'gdpr_art_6_1_a', 'protected' => true]);

        $cat1 = api::create_category((object)['name' => 'a']);

        // Set the defaults.
        list($purposevar, $categoryvar) = data_registry::var_names_from_context(
            \context_helper::get_class_for_level(CONTEXT_SYSTEM)
        );
        set_config($purposevar, $unprotected->get('id'), 'tool_dataprivacy');
        set_config($categoryvar, $cat1->get('id'), 'tool_dataprivacy');

        $contextinstance1 = api::set_context_instance((object) [
                'contextid' => $ctx1->id,
                'purposeid' => $unprotected->get('id'),
                'categoryid' => $cat1->get('id'),
            ]);

        $contextinstance2 = api::set_context_instance((object) [
                'contextid' => $ctx2->id,
                'purposeid' => $unprotected->get('id'),
                'categoryid' => $cat1->get('id'),
            ]);

        $contextinstance3 = api::set_context_instance((object) [
                'contextid' => $ctx3->id,
                'purposeid' => $protected->get('id'),
                'categoryid' => $cat1->get('id'),
            ]);

        $collection = new \core_privacy\local\request\contextlist_collection($s1->id);
        $contextlist = new \core_privacy\local\request\contextlist();
        $contextlist->set_component('tool_dataprivacy');
        $contextlist->add_from_sql('SELECT id FROM {context} WHERE id IN(:ctx2, :ctx3)', [
                'ctx2' => $ctx2->id,
                'ctx3' => $ctx3->id,
            ]);

        $collection->add_contextlist($contextlist);

        // Create the sample data request.
        $datarequest = api::create_data_request($s1->id, $type);
        $requestid = $datarequest->get('id');

        // Add the full collection with contexts 2, and 3.
        api::add_request_contexts_with_status($collection, $requestid, \tool_dataprivacy\contextlist_context::STATUS_PENDING);

        // Mark it as approved.
        api::update_request_contexts_with_status($requestid, \tool_dataprivacy\contextlist_context::STATUS_APPROVED);

        // Fetch the list.
        $approvedcollection = api::get_approved_contextlist_collection_for_request($datarequest);

        return (object) [
            'contexts' => (object) [
                'unused' => [
                    $ctx1->id,
                ],
                'used' => [
                    $ctx2->id,
                    $ctx3->id,
                ],
                'unprotected' => [
                    $ctx2->id,
                ],
                'protected' => [
                    $ctx3->id,
                ],
            ],
            'list' => $approvedcollection->get_contextlist_for_component('tool_dataprivacy'),
        ];
    }

    /**
     * Setup the basics with the specified retention period.
     *
     * @param   string  $system Retention policy for the system.
     * @param   string  $user Retention policy for users.
     * @param   string  $course Retention policy for courses.
     * @param   string  $activity Retention policy for activities.
     */
    protected function setup_basics(string $system, string $user, string $course = null, string $activity = null) : \stdClass {
        $this->resetAfterTest();

        $purposes = (object) [
            'system' => $this->create_and_set_purpose_for_contextlevel($system, CONTEXT_SYSTEM),
            'user' => $this->create_and_set_purpose_for_contextlevel($user, CONTEXT_USER),
        ];

        if (null !== $course) {
            $purposes->course = $this->create_and_set_purpose_for_contextlevel($course, CONTEXT_COURSE);
        }

        if (null !== $activity) {
            $purposes->activity = $this->create_and_set_purpose_for_contextlevel($activity, CONTEXT_MODULE);
        }

        return $purposes;
    }

    /**
     * Create a retention period and set it for the specified context level.
     *
     * @param   string  $retention
     * @param   int     $contextlevel
     * @return  purpose
     */
    protected function create_and_set_purpose_for_contextlevel(string $retention, int $contextlevel) : purpose {
        $purpose = new purpose(0, (object) [
            'name' => 'Test purpose ' . rand(1, 1000),
            'retentionperiod' => $retention,
            'lawfulbases' => 'gdpr_art_6_1_a',
        ]);
        $purpose->create();

        $cat = new category(0, (object) ['name' => 'Test category']);
        $cat->create();

        if ($contextlevel <= CONTEXT_USER) {
            $record = (object) [
                'purposeid'     => $purpose->get('id'),
                'categoryid'    => $cat->get('id'),
                'contextlevel'  => $contextlevel,
            ];
            api::set_contextlevel($record);
        } else {
            list($purposevar, ) = data_registry::var_names_from_context(
                    \context_helper::get_class_for_level(CONTEXT_COURSE)
                );
            set_config($purposevar, $purpose->get('id'), 'tool_dataprivacy');
        }

        return $purpose;
    }
}
