<?php
/**
 * Copyright 1999-2013 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file COPYING for license information (GPL). If you
 * did not receive this file, see http://www.horde.org/licenses/gpl.
 *
 * @author  Luc Saillard <luc.saillard@fr.alcove.com>
 * @author  Chuck Hagenbuch <chuck@horde.org>
 * @author  Jan Schneider <jan@horde.org>
 * @author  Michael J. Rubinsky <mrubinsk@horde.org>
 *
 * @package Kronolith
 */
class Kronolith_Event_Resource_Sql extends Kronolith_Event_Sql
{
    /**
     * The type of the calender this event exists on.
     *
     * @var string
     */
    public $calendarType = 'resource';

    /**
     * Returns a reference to a driver that's valid for this event.
     *
     * @return Kronolith_Driver  A driver that this event can use to save
     *                           itself, etc.
     */
    public function getDriver()
    {
        return Kronolith::getDriver('Resource', $this->calendar);
    }

    /**
     * Encapsulates permissions checking. For now, admins, and ONLY admins have
     * any permissions to a resource's events.
     *
     * @param integer $permission  The permission to check for.
     * @param string $user         The user to check permissions for.
     *
     * @return boolean
     */
    public function hasPermission($permission, $user = null)
    {
        return $GLOBALS['registry']->isAdmin();
    }

    /**
     * Saves changes to this event.
     *
     * @return integer  The event id.
     * @throws Kronolith_Exception
     */
    public function save()
    {
        if (!$this->initialized) {
            throw new Kronolith_Exception('Event not yet initialized');
        }

        /* Save */
        return $this->getDriver()->saveEvent($this);
    }
}
