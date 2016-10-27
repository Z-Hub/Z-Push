#!/usr/bin/env python

from MAPI.Util import *
from MAPI.Util.AddressBook import *
from MAPI.Time import *

skcache = {}

import inetmapi
import sys


print 'Usage: %s [<sslkey_file> <sslkey_pass>] [<username>]' % __file__
print 'If username is omitted, all users are scanned'
raw_input("Press <ENTER> to continue or CTRL-C to stop")

s = OpenECSession('SYSTEM', '', 'file:///var/run/zarafa')

sslkey_file = None
sslkey_pass = None
if len(sys.argv) > 1:
    (sslkey_file, sslkey_pass) = sys.argv[1:3]
    
if len(sys.argv) > 3:
    users = [sys.argv[3]]
else:
    users = GetUserList(s)

for username in users:
    print 'Processing user %s' % username

    try:   
        s = OpenECSession(username, '', 'file:///var/run/zarafa', sslkey_file = sslkey_file, sslkey_pass = sslkey_pass)

        st = GetDefaultStore(s)
        

        ab = s.OpenAddressBook(0, None, 0)
        identity = s.QueryIdentity()
        gabid = ab.GetDefaultDir()
        gabcontainer = ab.OpenEntry(gabid, None, 0)
        gab = gabcontainer.GetContentsTable(0)
        gab.SetColumns([PR_DISPLAY_NAME, PR_EMAIL_ADDRESS, PR_ENTRYID, PR_SEARCH_KEY, PR_ADDRTYPE], 0)

        root = st.OpenEntry(None, None, MAPI_MODIFY)
        calid = root.GetProps([PR_IPM_APPOINTMENT_ENTRYID], 0)[0]
        if calid.ulPropTag != PR_IPM_APPOINTMENT_ENTRYID:
            print 'User has no calendar'
            continue
            
        cal = st.OpenEntry(calid.Value, None, 0)

        t = cal.GetContentsTable(0)

        # Restrict to meetings only (AppointmentStateFlags >= 1)

        t.Restrict(SAndRestriction([
            SOrRestriction([
                SPropertyRestriction(RELOP_EQ, 0x8023000b, SPropValue(0x8023000b, True)), # Recurring OR
                SPropertyRestriction(RELOP_GE, 0x800e0040, SPropValue(0x800e0040, unixtime(time.time() - 1*7*24*60*60))) # Starts after now()-'1 week'
            ])
        ]), 0)
        t.SetColumns([PR_ENTRYID], 0)

        rows = t.QueryRows(-1, 0)

        for row in rows:
            modified = False

            message = st.OpenEntry(row[0].Value, None, MAPI_MODIFY)

            subject = message.GetProps([PR_SUBJECT], 0)[0].Value
            nameprops = message.GetProps([PR_SENT_REPRESENTING_ENTRYID, 0x80180003], 0)

            prevstatus = nameprops[1].Value

            if nameprops[0].Value == identity and prevstatus != 1:
                print "User is organizer of", "'"+subject+"'", "setting correct flag."
                message.SetProps([SPropValue(0x80180003, 1)])
                message.SaveChanges(0)
            elif nameprops[0].Value != identity and prevstatus == 1:
                print "User is attendee of", "'"+subject+"'", "setting correct flag."
                message.SetProps([SPropValue(0x80180003, 5)])
                message.SaveChanges(0)
            else:
                print "Correct property set for", "'"+subject+"'", "skipping."

    except MAPIError, e:
        print e
        pass
