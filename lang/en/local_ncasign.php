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

$string['pluginname'] = 'NCA Signing (demo)';
$string['ncasign:managejobs'] = 'Manage NCA signing jobs';

$string['settingsheader'] = 'Demo NCA signing workflow';
$string['enabled'] = 'Enable plugin';
$string['manualwindowhours'] = 'Manual signing window (hours)';
$string['autosignenabled'] = 'Enable auto-sign fallback';
$string['autosignnote'] = 'Auto-sign note';
$string['notifyroleids'] = 'Notify role IDs (comma separated)';
$string['certurltemplate'] = 'Certificate URL template';
$string['engineerprotocoltemplatepath'] = 'Engineer protocol template PDF path';
$string['engineerprotocoltemplatepath_desc'] = 'Absolute server path to the engineer protocol PDF template used by the local FPDI/TCPDF generator.';

$string['taskprocessjobs'] = 'Process overdue NCA sign jobs';

$string['jobs'] = 'Signing jobs';
$string['createdemojob'] = 'Create demo job';
$string['courseid'] = 'Course ID';
$string['userid'] = 'User ID (student)';
$string['signeremails'] = 'Signer emails (comma separated)';
$string['createjob'] = 'Create job';
$string['status'] = 'Status';
$string['deadline'] = 'Manual deadline';
$string['manualsigned'] = 'Manual signatures';
$string['autosigned'] = 'Auto-signed';
$string['artifacts'] = 'Artifacts';
$string['documenttitle'] = 'Document title';
$string['documenttype'] = 'Document type';

$string['signtitle'] = 'Certificate signature request';
$string['signinstructions'] = 'Use NCALayer below to create a real CMS signature for this request.';
$string['ncarunninghint'] = 'NCALayer must be installed and running on this signer machine.';
$string['storage'] = 'Key storage';
$string['loadtokens'] = 'Load available tokens';
$string['signwithnca'] = 'Sign with NCALayer';
$string['signedok'] = 'Signature recorded successfully.';
$string['invalidtoken'] = 'Invalid or expired signing token.';
$string['alreadysigned'] = 'This request has already been signed.';
$string['invalidpayload'] = 'Invalid signing payload received.';
$string['emptysignature'] = 'Empty signature received from NCALayer.';
$string['draftpdf'] = 'Draft PDF';
$string['verifytitle'] = 'Document verification';
$string['verifyinvalidlink'] = 'Invalid verification link.';
$string['verifynotfound'] = 'The requested document could not be found.';
$string['verifyauthentic'] = 'AUTHENTIC';
$string['verifymodified'] = 'DOCUMENT MODIFIED';
$string['verifyunavailable'] = 'VERIFICATION UNAVAILABLE';
$string['verifydocumentinfo'] = 'Document info';
$string['verifyuserinfo'] = 'User info';
$string['verifysignatures'] = 'Signature block';
$string['verifyintegrity'] = 'Integrity status';
$string['verifydocumenttype'] = 'Document type';
$string['verifydocumenttitle'] = 'Document title';
$string['verifycoursename'] = 'Course name';
$string['verifyissuedate'] = 'Issue date';
$string['verifyorganisation'] = 'Issuing organisation';
$string['verifyfullname'] = 'Full name';
$string['verifycompletiondate'] = 'Completion date';
$string['verifyposition'] = 'Position';
$string['verifysignedat'] = 'Signed at';
$string['verifyhash'] = 'Stored SHA-256';
$string['verifycurrenthash'] = 'Current SHA-256';
$string['verifynosigners'] = 'No signer records found for this document.';
$string['verifypublicid'] = 'Public document ID';
