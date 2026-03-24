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

$string['pluginname'] = 'NCA Signing';
$string['ncasign:managejobs'] = 'Manage NCA signing jobs';

$string['settingsheader'] = 'NCA signing workflow';
$string['enabled'] = 'Enable plugin';
$string['manualwindowhours'] = 'Manual signing window (hours)';
$string['autosignenabled'] = 'Enable auto-sign fallback';
$string['autosignnote'] = 'Auto-sign note';
$string['certurltemplate'] = 'Certificate URL template';
$string['kalkanheader'] = 'Server-side CMS verification';
$string['kalkantrustpath'] = 'Kalkan trust path';
$string['kalkantrustpath_desc'] = 'Absolute server path to the RK/NCA trust store or validation bundle used by KalkanCrypt.';
$string['kalkanvalidationmode'] = 'Certificate validation mode';
$string['kalkanvalidationmode_desc'] = 'OCSP is preferred. CRL fallback can be enabled if OCSP is unavailable in the environment.';
$string['kalkanvalidationmode_ocsp'] = 'OCSP';
$string['kalkanvalidationmode_crl'] = 'CRL';
$string['kalkanvalidationmode_ocspcrl'] = 'OCSP then CRL fallback';
$string['kalkantsaurl'] = 'TSA URL';
$string['kalkantsaurl_desc'] = 'Timestamping service URL used by the server-side Kalkan stack when applicable.';

$string['taskprocessjobs'] = 'Process overdue NCA sign jobs';

$string['jobs'] = 'Signing jobs';
$string['createdemojob'] = 'Create demo job';
$string['templateprofiles'] = 'Template profiles';
$string['templateprofileadd'] = 'Add template profile';
$string['templateprofileedit'] = 'Edit template profile';
$string['templateprofilesback'] = 'Back to template profiles';
$string['templateprofilesempty'] = 'No template profiles have been configured yet.';
$string['templateprofilenotice_saved'] = 'Template profile saved.';
$string['templateprofilenotice_deleted'] = 'Template profile deleted.';
$string['templaterenderer'] = 'Template renderer';
$string['templaterenderer_desc'] = 'Renderer family used to generate this document. For now only the engineer protocol renderer is implemented.';
$string['templatepathlabel'] = 'Template PDF path';
$string['templatepathlabel_desc'] = 'Absolute server path to the source PDF for this profile.';
$string['templatecourses'] = 'Mapped course IDs';
$string['templatecourses_desc'] = 'Comma-separated Moodle course IDs that should generate this template on completion.';
$string['templatesigners'] = 'Signer sequence';
$string['templatesigners_desc'] = 'One signer per line in the format: email|display name|position|expected IIN. The IIN is optional but recommended for server-side signer verification.';
$string['templatelayoutconfig'] = 'Layout config (JSON)';
$string['templatelayoutconfig_desc'] = 'Per-template coordinate and field mapping JSON. This is stored now for future renderer expansion.';
$string['templateactive'] = 'Active';
$string['templateprofilelayoutinvalid'] = 'Layout config must be valid JSON.';
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
$string['signernotactive'] = 'This signing link is not active yet. A previous signer still needs to sign first.';
$string['invalidpayload'] = 'Invalid signing payload received.';
$string['emptysignature'] = 'Empty signature received from NCALayer.';
$string['nodocumentpayload'] = 'No document PDF is currently attached for signing.';
$string['verificationfailed'] = 'Signature verification failed: {$a}';
$string['verificationunavailable'] = 'Server-side signature verification is not available on this server.';
$string['signeriinmismatch'] = 'Signer IIN does not match the configured commission member.';
$string['draftpdf'] = 'Draft PDF';
$string['currentsignedpdf'] = 'Current signed PDF';
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
