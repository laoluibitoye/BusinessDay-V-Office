-- email_templates_seed.sql
-- Run ONCE in phpMyAdmin on hrindexx_hri_webmail
-- DO NOT upload this file to the web root.
--
-- Seeds a shared template library across all twelve categories.
--
-- NO SIGNATURES. Every body stops at the sign-off line ("Kind regards,") —
-- the compose panel appends the sender's own signature from user_signatures,
-- so a template carrying one would produce two.
--
-- Templates are owned by the first head_it account and marked is_shared=1 so
-- everyone can use them. {{placeholders}} are substituted at send time.

SET @owner := (SELECT id FROM users WHERE role='head_it' AND is_active=1 ORDER BY id LIMIT 1);
SET @owner := COALESCE(@owner, (SELECT id FROM users WHERE is_active=1 ORDER BY id LIMIT 1));

INSERT INTO email_templates (user_id, name, subject, body, category, is_shared) VALUES

-- ── RECRUITMENT ──────────────────────────────────────────────────────────────
(@owner, 'Interview Invitation', 'Interview Invitation — {{role_title}} at HR Indexx Limited',
'Dear {{candidate_name}},\n\nThank you for your application for the position of {{role_title}}.\n\nWe would like to invite you to an interview:\n\n  Date:     {{interview_date}}\n  Time:     {{interview_time}}\n  Venue:    12 Macarthy Street, Onikan, Lagos Island\n  Format:   {{interview_format}}\n\nPlease bring a valid means of identification and copies of your credentials.\n\nKindly confirm your attendance by replying to this email.\n\nKind regards,', 'recruitment', 1),

(@owner, 'Offer of Employment', 'Offer of Employment — {{role_title}}',
'Dear {{candidate_name}},\n\nFollowing your interview, we are pleased to offer you the position of {{role_title}} at HR Indexx Limited.\n\n  Start date:   {{start_date}}\n  Reporting to: {{line_manager}}\n  Location:     12 Macarthy Street, Onikan, Lagos Island\n\nYour full offer letter and terms of engagement are attached. Please sign and return a copy to confirm your acceptance by {{acceptance_deadline}}.\n\nWe look forward to welcoming you.\n\nKind regards,', 'recruitment', 1),

(@owner, 'Application Unsuccessful', 'Your application — {{role_title}}',
'Dear {{candidate_name}},\n\nThank you for taking the time to apply for the position of {{role_title}} and for attending our selection process.\n\nAfter careful consideration we will not be progressing your application on this occasion. The standard was high and the decision was a difficult one.\n\nWe will keep your details on file and will be in touch should a suitable role arise.\n\nWe wish you every success.\n\nKind regards,', 'recruitment', 1),

(@owner, 'Candidate Assessment Result', 'Assessment Outcome — {{role_title}}',
'Dear {{candidate_name}},\n\nThank you for completing the assessment for {{role_title}}.\n\n  Assessment:  {{assessment_name}}\n  Outcome:     {{outcome}}\n\n{{feedback_summary}}\n\nWe will contact you regarding next steps within {{timeframe}}.\n\nKind regards,', 'recruitment', 1),

-- ── ONBOARDING ───────────────────────────────────────────────────────────────
(@owner, 'Welcome — First Day', 'Welcome to HR Indexx Limited, {{staff_name}}',
'Dear {{staff_name}},\n\nWelcome to HR Indexx Limited. We are delighted to have you join us as {{role_title}}.\n\nYour first day is {{start_date}}. Please arrive at 12 Macarthy Street, Onikan, Lagos Island for {{report_time}} and ask for {{contact_person}}.\n\nPlease bring:\n  - A valid means of identification\n  - Your bank account details (for payroll)\n  - Next of kin details\n  - Originals of your credentials\n\nYour IT account and email address will be ready on arrival.\n\nKind regards,', 'onboarding', 1),

(@owner, 'IT Account Created', 'Your HRI Mail account is ready',
'Dear {{staff_name}},\n\nYour HR Indexx account has been created.\n\n  Email:    {{staff_email}}\n  Portal:   https://mail.hrindexx.com\n  Password: shared separately\n\nPlease sign in and change your password immediately. Your password also controls your mailbox, so keep it secure and do not share it.\n\nIf you have any difficulty, raise a ticket under IT Support in the portal.\n\nKind regards,', 'onboarding', 1),

-- ── HR ───────────────────────────────────────────────────────────────────────
(@owner, 'Confirmation of Employment', 'Confirmation of Employment — {{staff_name}}',
'Dear {{staff_name}},\n\nFollowing the successful completion of your probationary period on {{probation_end}}, we are pleased to confirm your appointment as {{role_title}} at HR Indexx Limited.\n\nAll terms of your engagement remain as set out in your letter of employment.\n\nCongratulations, and thank you for your contribution so far.\n\nKind regards,', 'hr', 1),

(@owner, 'Probation Review Due', 'Probation review — {{staff_name}}',
'Dear {{line_manager}},\n\n{{staff_name}} ({{role_title}}) reaches the end of their probationary period on {{probation_end}}.\n\nPlease complete the probation review and return your recommendation to HR before that date.\n\nKind regards,', 'hr', 1),

(@owner, 'Employment Verification', 'Employment Verification — {{staff_name}}',
'Dear Sir/Madam,\n\nThis is to confirm that {{staff_name}} is employed by HR Indexx Limited as {{role_title}}.\n\n  Date of engagement: {{start_date}}\n  Status:             {{employment_status}}\n\nThis letter is issued at the request of the staff member and without liability on the part of HR Indexx Limited.\n\nKind regards,', 'hr', 1),

-- ── LEAVE MANAGEMENT ─────────────────────────────────────────────────────────
(@owner, 'Leave Approved', 'Leave Approved — {{start_date}} to {{end_date}}',
'Dear {{staff_name}},\n\nYour request for {{leave_type}} has been approved.\n\n  From:     {{start_date}}\n  To:       {{end_date}}\n  Days:     {{days}}\n  Cover:    {{cover_staff}}\n\nPlease ensure handover notes are with your cover before you leave.\n\nKind regards,', 'leave', 1),

(@owner, 'Leave Declined', 'Leave Request — {{start_date}} to {{end_date}}',
'Dear {{staff_name}},\n\nYour request for {{leave_type}} from {{start_date}} to {{end_date}} has not been approved on this occasion.\n\nReason: {{reason}}\n\nPlease speak with your line manager to agree alternative dates.\n\nKind regards,', 'leave', 1),

-- ── EXIT & OFFBOARDING ───────────────────────────────────────────────────────
(@owner, 'Resignation Acknowledged', 'Acknowledgement of Resignation — {{staff_name}}',
'Dear {{staff_name}},\n\nWe acknowledge receipt of your resignation letter dated {{resignation_date}}.\n\nYour last working day will be {{last_working_day}}, in line with your notice period.\n\nHR will contact you regarding handover, return of company property and your final entitlements.\n\nThank you for your service to HR Indexx Limited.\n\nKind regards,', 'exit', 1),

(@owner, 'Exit Clearance', 'Exit Clearance — {{staff_name}}',
'Dear {{staff_name}},\n\nAs your last working day of {{last_working_day}} approaches, please complete the following before departure:\n\n  - Hand over all outstanding work to {{handover_to}}\n  - Return company property (ID card, devices, keys)\n  - Clear any outstanding advances or retirements\n  - Complete the exit interview with HR\n\nYour final settlement will be processed once clearance is confirmed.\n\nKind regards,', 'exit', 1),

-- ── TRAINING ─────────────────────────────────────────────────────────────────
(@owner, 'Training Invitation', 'Training — {{training_title}}',
'Dear {{staff_name}},\n\nYou have been nominated to attend the following training:\n\n  Programme: {{training_title}}\n  Date:      {{training_date}}\n  Time:      {{training_time}}\n  Venue:     {{venue}}\n  Facilitator: {{facilitator}}\n\nPlease confirm your attendance and inform your line manager so cover can be arranged.\n\nKind regards,', 'training', 1),

(@owner, 'Training Completion', 'Certificate of Completion — {{training_title}}',
'Dear {{staff_name}},\n\nCongratulations on completing {{training_title}} on {{completion_date}}.\n\nYour certificate is attached and a copy has been added to your personnel file.\n\nKind regards,', 'training', 1),

-- ── PAYROLL ──────────────────────────────────────────────────────────────────
(@owner, 'Payslip Notification', 'Payslip — {{pay_period}}',
'Dear {{staff_name}},\n\nYour payslip for {{pay_period}} is attached.\n\nPlease review it and raise any query with the Accounts team within five working days.\n\nThis document is confidential and intended for you alone.\n\nKind regards,', 'payroll', 1),

(@owner, 'Payroll Query Response', 'Re: Payroll query — {{pay_period}}',
'Dear {{staff_name}},\n\nThank you for your query regarding your {{pay_period}} payslip.\n\n  Query:     {{query_summary}}\n  Finding:   {{finding}}\n  Action:    {{action_taken}}\n\nIf anything remains unclear, please reply to this email.\n\nKind regards,', 'payroll', 1),

-- ── FINANCE & ACCOUNTS ───────────────────────────────────────────────────────
(@owner, 'Payment Advice', 'Payment Advice — {{reference}}',
'Dear {{beneficiary_name}},\n\nPlease find below details of a payment made to your account:\n\n  Reference: {{reference}}\n  Amount:    NGN {{amount}}\n  Date:      {{payment_date}}\n  Bank:      {{bank_name}}\n  Narration: {{narration}}\n\nKindly acknowledge receipt.\n\nKind regards,', 'finance', 1),

(@owner, 'Retirement Reminder', 'Outstanding retirement — {{reference}}',
'Dear {{staff_name}},\n\nOur records show an advance that has not yet been retired:\n\n  Reference: {{reference}}\n  Amount:    NGN {{amount}}\n  Issued:    {{issue_date}}\n\nPlease submit your retirement with supporting receipts through the Internal Request System within {{deadline}}.\n\nKind regards,', 'finance', 1),

(@owner, 'Invoice Submission', 'Invoice — {{invoice_number}}',
'Dear {{client_name}},\n\nPlease find attached our invoice {{invoice_number}} dated {{invoice_date}} for NGN {{amount}} in respect of {{service_description}}.\n\nPayment terms: {{payment_terms}}\n\nOur bank details are shown on the invoice. Kindly quote the invoice number on your remittance.\n\nKind regards,', 'finance', 1),

-- ── CLIENT SERVICE ───────────────────────────────────────────────────────────
(@owner, 'Client Onboarding', 'Welcome to HR Indexx Limited',
'Dear {{client_name}},\n\nThank you for choosing HR Indexx Limited as your outsourcing partner.\n\n  Service:            {{service_type}}\n  Commencement:       {{start_date}}\n  Account Manager:    {{account_manager}}\n  Direct line:        {{contact_phone}}\n\nYour account manager will be in touch within 48 hours to agree service levels and reporting arrangements.\n\nKind regards,', 'client_service', 1),

(@owner, 'Service Review Meeting', 'Service Review — {{client_name}}',
'Dear {{contact_name}},\n\nWe would like to schedule our {{review_period}} service review.\n\n  Proposed date: {{meeting_date}}\n  Time:          {{meeting_time}}\n  Format:        {{meeting_format}}\n\nAgenda:\n  - Service performance against agreed SLA\n  - Open issues and resolutions\n  - Headcount and placement update\n  - Any other business\n\nPlease confirm the date suits you.\n\nKind regards,', 'client_service', 1),

(@owner, 'Staff Placement Confirmation', 'Placement Confirmation — {{client_name}}',
'Dear {{contact_name}},\n\nWe confirm the following placement:\n\n  Staff:      {{staff_name}}\n  Role:       {{role_title}}\n  Start date: {{start_date}}\n  Location:   {{location}}\n\nAll pre-engagement checks have been completed. Please let your account manager know if anything further is required.\n\nKind regards,', 'client_service', 1),

-- ── COMPLIANCE ───────────────────────────────────────────────────────────────
(@owner, 'SLA Expiry Notice', 'SLA Expiry — {{sla_name}}',
'Dear {{contact_name}},\n\nThis is to notify you that the following agreement is due to expire:\n\n  Agreement:   {{sla_name}}\n  Client:      {{client_name}}\n  Expiry date: {{expiry_date}}\n  Days left:   {{days_remaining}}\n\nPlease advise whether you wish to renew so we can prepare the necessary documentation.\n\nKind regards,', 'compliance', 1),

(@owner, 'Data Protection Notice', 'Data Protection Notice — HR Indexx Limited',
'Dear {{recipient_name}},\n\nHR Indexx Limited processes personal data in line with the Nigeria Data Protection Act 2023.\n\n  Data Controller: HR Indexx Limited (RC 446051)\n  NDPC Registration: NDPC/DCP/12819\n  Data Protection Officer: {{dpo_name}}\n\nWe collect and process your data only for the purposes stated at the point of collection, retain it no longer than necessary, and do not share it without a lawful basis.\n\nTo exercise your rights of access, rectification or erasure, contact our DPO.\n\nKind regards,', 'compliance', 1),

(@owner, 'Compliance Document Renewal', 'Renewal Required — {{document_name}}',
'Dear {{owner_name}},\n\nThe following compliance document requires renewal:\n\n  Document:    {{document_name}}\n  Category:    {{category}}\n  Expires:     {{expiry_date}}\n  Days left:   {{days_remaining}}\n\nPlease begin the renewal process and upload the replacement to the Compliance Tracker once obtained.\n\nKind regards,', 'compliance', 1),

-- ── IT SUPPORT ───────────────────────────────────────────────────────────────
(@owner, 'IT Ticket Resolved', 'Resolved — Ticket #{{ticket_number}}',
'Dear {{staff_name}},\n\nYour IT support request has been resolved.\n\n  Ticket:     #{{ticket_number}}\n  Issue:      {{issue_type}}\n  Resolution: {{resolution}}\n\nIf the problem returns, reply to this email or raise a new ticket in the portal.\n\nKind regards,', 'it', 1),

(@owner, 'Scheduled Maintenance', 'Scheduled Maintenance — {{maintenance_date}}',
'Dear Colleagues,\n\nPlease note scheduled maintenance on the following systems:\n\n  Systems:  {{systems_affected}}\n  Date:     {{maintenance_date}}\n  Window:   {{start_time}} to {{end_time}}\n  Impact:   {{expected_impact}}\n\nPlease save your work and sign out before the window begins.\n\nKind regards,', 'it', 1),

(@owner, 'Password Reset', 'Your password has been reset',
'Dear {{staff_name}},\n\nYour HRI Mail password has been reset at your request.\n\nA temporary password has been shared with you separately. Please sign in at https://mail.hrindexx.com and change it immediately.\n\nThis password also controls your mailbox. Do not share it with anyone, including IT.\n\nKind regards,', 'it', 1),

-- ── GENERAL ──────────────────────────────────────────────────────────────────
(@owner, 'Meeting Invitation', 'Meeting — {{meeting_subject}}',
'Dear {{recipient_name}},\n\nYou are invited to a meeting:\n\n  Subject:  {{meeting_subject}}\n  Date:     {{meeting_date}}\n  Time:     {{meeting_time}}\n  Venue:    {{venue}}\n\nAgenda:\n{{agenda}}\n\nPlease confirm your attendance.\n\nKind regards,', 'general', 1),

(@owner, 'Document Request', 'Document Request — {{document_name}}',
'Dear {{recipient_name}},\n\nWe require the following document to complete {{purpose}}:\n\n  Document: {{document_name}}\n  Needed by: {{deadline}}\n\nPlease send it to this address or upload it to the portal.\n\nKind regards,', 'general', 1),

(@owner, 'Acknowledgement', 'Re: {{original_subject}}',
'Dear {{recipient_name}},\n\nThank you for your email regarding {{original_subject}}.\n\nWe have received it and will respond fully by {{response_date}}.\n\nKind regards,', 'general', 1);


-- ── VERIFY ───────────────────────────────────────────────────────────────────
SELECT category, COUNT(*) AS templates
FROM email_templates WHERE is_shared = 1
GROUP BY category ORDER BY category;

-- Confirm no template carries a signature block (should return zero rows)
SELECT id, name FROM email_templates
WHERE body LIKE '%NDPA%' OR body LIKE '%RC 446051%' OR body LIKE '%hri-logo%';
