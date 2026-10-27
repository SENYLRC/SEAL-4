def build(g):
    g.h1("6. Running the ILL service")
    g.p("This section is for the people who use the site every day. The ILL pages share a sidebar menu; each person sees only "
        "the sections their role allows.")
    g.table(["Sidebar section", "Pages", "Who sees it"], [
        ["Edit Profiles", "User Profile (My Profile), Library Profile, View Library Accounts", "All staff"],
        ["Request Status", "Borrowing Requests, Lending Requests", "All staff"],
        ["Statistics", "Borrowing Statistics, Lending Statistics", "All staff"],
        ["System Staff", "Manage Accounts, Manage All Requests, Manage Library, System Statistics (for their own library system)", "Library System Staff"],
        ["ILL Admin", "Manage All Libraries, User Accounts, All Requests, organization statistics, Overall System Stats, ILLiad Mapping, "
                      "Resend a Request, Automatic Fallback (Rota), Email Harvest", "ILL Administrators and Administrators"],
    ], [1.4, 3.6, 1.5])

    # 6.1 accounts
    g.h2("6.1 Getting an account")
    g.steps([
        "The new user registers on the site's registration page: name, email, institution, LOC code, phone, "
        "and the invite code if one is set.",
        "If approval is required, the account is approved (or denied) by an Administrator or ILL Administrator, from the approval email, "
        "**User Accounts** or the **Pending** view on the WordPress **Users** screen, or by the Library System Staff of the person's home "
        "system on **Manage Accounts**.",
        "The new user gets a welcome email with a **Set your password** button (the link works once and expires after the time set under Users & Registration, 7 days by default; "
        "**Lost your password?** on the sign-in page sends a new one). If the account is waiting for approval, the email says so. "
        "Accounts created by an administrator or by library system staff get the same email.",
        "An administrator (or the user's library system staff) checks the role: **Library Staff** for most people.",
        "At first login the user lands on **My Profile**. If two-factor authentication is required for their role, they set it up there: "
        "add a security key or passkey, or scan the QR code with an authenticator app, then save their backup codes.",
    ])
    g.h3("Two-factor sign-in: security keys, passkeys and codes")
    g.p("On **My Profile → Two-Factor Authentication** each person manages their own methods, one card per method with a status badge. "
        "Two-factor is on as soon as one is added. Every change asks for the person's password (or a current code) in place.")
    g.bullets([
        "**Add a key:** select **Add a security key or passkey**, choose a YubiKey (or other security key) or a passkey on this computer or phone, "
        "give it a name, enter the password, select **Continue and touch your key**, then touch the key (or follow the device's prompt).",
        "**Remove a key:** each key is listed with when it was added and last used; select **Remove** beside it and enter the password.",
        "**Authenticator app:** scan the QR code and enter a code to turn it on. It can sit beside keys, and can be removed while a key remains.",
        "**Backup codes:** 10 one-time codes are shown once, when the first method is added. **Generate new backup codes** replaces them. "
        "Removing the last method also removes the codes.",
        "**Turn off two-factor** removes every method (not available to roles that require it).",
    ])
    g.p("At sign-in, after the password, the second screen offers **Use security key or passkey** (touch the key) and a box for a 6-digit code "
        "or a backup code. Five failed attempts send the person back to the password screen.")
    g.note("Password managers such as Bitwarden or 1Password may pop up and offer to save a passkey when a key is added. To use a YubiKey, "
           "choose the manager's “use a hardware key” option, or turn off its passkey prompts.", "tip")
    g.note("The LOC code on the profile decides which library a person acts for. Only administrators can change it, or add extra "
           "LOC codes so one person can work for several libraries.")

    # 6.2 borrowing
    g.h2("6.2 Borrowing: requesting an item")
    g.steps([
        "Search the catalog from the search box and open the title.",
        "Click **Request**. The request page shows the item and the libraries that can lend it. Libraries that can't "
        "(suspended, or not lending this item type) are greyed out with the reason.",
        "Choose the lending library.",
        "Optional: tick **Automatic fallback** and pick up to two backup libraries in order. If the first library says no, or "
        "doesn't answer in 5 business days, the request moves to the next one by itself. If you leave the backup boxes blank, "
        "the system uses the next libraries in the list.",
        "Fill in **Need by date**, a **Note** to the lender and, if your library uses them, a **Patron name or barcode** "
        "(this stays with your library). For articles, fill in the article title, author, volume, issue, pages and date, "
        "and the copyright compliance choice.",
        "Submit. The confirmation shows the ILL number, the library and email address it went to, the details, and your "
        "requester information. You get a copy by email.",
    ])

    g.h2("6.3 Borrowing Requests page")
    g.p("Lists your library's requests with filters (status, date range, ILL number). Actions depend on where the request is:")
    g.table(["Situation", "Actions"], [
        ["No answer yet", "Cancel"],
        ["Filled, not yet received", "Mark Received, Print Slip"],
        ["Received", ["Request Renewal (a reason is required, sent to the lender), Return Item (return method and note), Print Slip.",
                      "While a renewal is waiting, it shows “Renewal requested — waiting for the lender”."]],
        ["Not filled, expired, canceled, returned", "None"],
    ], [2.0, 4.5])
    g.p("Tick several requests to **Cancel**, **Renew**, **Receive** or **Return** them in one step (bulk actions). "
        "Notes from both libraries, renewal notes and return details appear under each request.")

    # lending
    g.h2("6.4 Lending: answering requests")
    g.p("A new request arrives by email with **Yes, will fill** and **No, can't fill** buttons. Either button opens the answer "
        "form on the site (log in if asked). It can also be answered from **Lending Requests**.")
    g.bullets([
        "**Yes**: give the due date, the shipping method and an optional note. The borrower is emailed.",
        "**No**: choose a reason (In Use, Lost, Non-Circulating, Not on Shelf, Poor Condition, Too New, Not Owned) and an optional note. "
        "The borrower is emailed, and the Not-Filled CC addresses are copied. With Automatic Fallback on, the request goes to the next library.",
    ])
    g.h3("Lending Requests page")
    g.table(["Situation", "Actions"], [
        ["No answer yet", "Yes, Will Fill / No, Can't Fill"],
        ["Filled or Not Filled, within 14 days of answering", "**Edit Fill Status**: opens to show the opposite answer, if the answer needs changing."],
        ["Filled, item received by the borrower", "Edit Due Date; Check Item Back In after 14 days"],
        ["Renewal requested", "Approve Renewal (new due date and an optional note), Deny Renewal (optional reason), Check Item Back In"],
        ["Returned by the borrower", "Check Item Back In"],
        ["Filled more than 14 days ago", "Check Item Back In"],
        ["Expired, canceled or checked in", "None"],
    ], [2.4, 4.1])
    g.p("**Print Slip** prints a pull / routing slip for filled items. Tick several requests and use **Print Pull Slips for "
        "Selected** to print them together. Bulk actions: **Mark Not Filled** and **Check Item Back In**.")

    g.h2("6.5 Library profile and directory")
    g.bullets([
        "**Library Profile**: staff keep their library's name, ILL email (several addresses separated by commas or semicolons), phone, "
        "address and OCLC symbol up to date.",
        "**Suspend** the library while it can't lend (closures, inventory): tick Suspended and give a **Suspend Until** date. Borrowers see "
        "the library greyed out. The suspension ends automatically after that date (check_suspend.php). A suspension with no end date stays until it is removed.",
        "**Directory of Participating Libraries**: public list of libraries. Phone numbers and email addresses are shown only to logged-in users. "
        "When the library table has the optional delivery columns, each library also shows the delivery methods it set on its Library Profile.",
        "**View Library Accounts**: the staff accounts registered for your library.",
    ])
    g.h2("6.6 Statistics")
    g.bullets([
        "**Borrowing / Lending Statistics**: your library's requests by status for a date range.",
        "**System Statistics**: the same, for every library in your library system (Library System Staff).",
        "**Organization and Overall System Statistics**: totals and fill rates across the service (ILL Administrators).",
        "A weekly statistics email is sent every Monday by the scheduled scripts.",
    ])

    # admin
    g.h2("6.7 Administering libraries")
    g.p("**Manage All Libraries** (ILL Admin) lists every library, with filters for missing aliases, missing ILL emails, participation, "
        "suspension and library system. **All Libraries** means none of those four yes/no filters: ticking it clears them, and ticking any "
        "of them clears All Libraries. The filters work whether a library table stores participation and suspension as 1/0 or Yes/No; "
        "the same applies to system staff's **Manage Library** and to **System Statistics** (participating, not participating, suspended).")
    g.table(["Field", "Meaning"], [
        ["Library name, LOC code", "The LOC code identifies the library everywhere; it must match the LOC code on its staff's profiles."],
        ["Library system", "Must be a code from Org Rules → Library Systems."],
        ["Alias", "The text the catalog uses for this library's holdings. Holdings are matched to libraries by alias (section 4.8)."],
        ["ILL email", "Where requests are sent. Several addresses: separate with commas or semicolons."],
        ["Participant", "No = never offered to borrowers."],
        ["Suspended / Suspend Until", "Temporarily not lending (section 6.5)."],
        ["Loan questions", "Which item types the library lends (books, periodicals, AV, e-books, e-journals), used with Org Rules → Item Types."],
        ["Delivery", "Which delivery methods the library accepts."],
        ["ILLiad", "Whether the library uses ILLiad, its ILLiad address, API key and default loan period in days (section 7)."],
    ], [1.8, 4.7])
    g.p("**Manage Library** gives Library System Staff the same editor, limited to libraries in their own system. "
        "**Export Data** downloads the library directory as a CSV file.")

    g.h2("6.8 Administering user accounts")
    g.bullets([
        "**User Accounts** (ILL Admin): find any account by library system, role, name, email or LOC code, and open it to edit "
        "its profile, library (LOC) codes and role.",
        "**Manage Accounts** (Library System Staff): add, edit, reset the password of, or delete Library Staff accounts in their own system.",
        "Both lists show each account's security in one column: **Password** strength (Weak, Fair, Good or Strong; hover for when it was rated; “Not rated yet” until "
        "the person next signs in or changes it; the password itself is never stored) and **Two-factor** status: **On** with how it is set up "
        "(e.g. “2 keys, app”), **Not set up** in red when their role requires it, **Off** when it is optional. Both are shown only for "
        "accounts you can manage (“—” otherwise), so an ILL Administrator doesn't see an Administrator's password strength.",
        "**History** beside each account shows every recorded change to it: registration, approval or denial, role, library and profile "
        "fields, password and two-factor changes, who made them and when (section 4.12). It appears only for accounts you can manage: "
        "ILL Administrators for anyone except Administrators and other ILL Administrators, Library System Staff for Library Staff in their system.",
        "**Approving registrations**: accounts waiting for approval show a **Pending approval** label, with **Approve** and **Deny** "
        "buttons, and a notice at the top of the list (“2 accounts are waiting for approval — Show them”). ILL Administrators can approve "
        "any account they can edit on **User Accounts** (tick **Waiting for approval only** to list just those); Library System Staff can "
        "approve Library Staff in their own system on **Manage Accounts**. The approval email links and the WordPress Users screen "
        "(one at a time or in bulk) still work. The person is emailed either way, and the decision is recorded in Change History.",
        "ILL Administrators cannot edit, delete or promote Administrators or other ILL Administrators, and cannot give those roles.",
        "**Lost key or phone:** a person can sign in with a backup code. If those are gone too, an Administrator or ILL Administrator opens their "
        "account under WordPress **Users**, ticks **Reset this user's two-factor authentication** and saves; this removes their keys, authenticator "
        "app and backup codes and emails them. ILL Administrators can't reset Administrators or other ILL Administrators.",
        "**Email Harvest** exports staff email addresses (e.g. for a mailing list).",
        "Users can be exported to CSV from the WordPress Users menu (Export Users module).",
    ])
    g.h2("6.9 Watching all requests")
    g.p("**All Requests** (ILL Admin) and **Manage All Requests** (own system) list every request, with filters by status, library, "
        "date and ILL number. The status column shows badges: Filled, Not Filled, Reminder Sent and Expired. All notes "
        "appear under each request.")
    g.h3("Resend a Request")
    g.p("For when a library says it never got a request, or an ILLiad submission failed. Enter the ILL number to see the request, then:")
    g.bullets([
        "**Resend the emails**: to the lender, to the borrower, or both.",
        "**Send to ILLiad**: submit it to the lending library's ILLiad again. The page warns if it already has an ILLiad transaction number.",
        "**Waiting to be sent to ILLiad**: requests to ILLiad libraries that have no transaction number yet, such as one moved by "
        "Automatic Fallback overnight.",
        "**Recent resends**: a log of who resent what, and when.",
    ])
    g.h3("Automatic Fallback (Rota)")
    g.p("A read-only view of every request using Automatic Fallback: which library has it now, how many libraries have been tried, "
        "and the full history (who declined or timed out, when, and why). Only administrators can see it, because decline reasons "
        "can be frank feedback about another library.")
