import { createContext, useContext, useEffect, useState } from 'react';

// English is the key; Tamil translations below. Missing keys fall back to English.
const TA = {
  // Navigation
  'Dashboard': 'டாஷ்போர்டு', 'Members': 'உறுப்பினர்கள்', 'Check-ins & sessions': 'வருகை & அமர்வுகள்', 'Billing & GST': 'பில்லிங் & GST',
  'Leads & CRM': 'லீட்ஸ் & CRM', 'Classes & PT': 'வகுப்புகள் & PT', 'Staff & payroll': 'பணியாளர் & சம்பளம்', 'Store & inventory': 'கடை & சரக்கு',
  'WhatsApp & campaigns': 'WhatsApp & பிரச்சாரங்கள்', 'Reports': 'அறிக்கைகள்', 'Devices & access': 'சாதனங்கள் & அணுகல்', 'Settings': 'அமைப்புகள்',
  'AI insights': 'AI நுண்ணறிவு', 'Plans & packages': 'திட்டங்கள் & பேக்கேஜ்கள்', 'Branches': 'கிளைகள்', 'Front desk screen': 'முன் மேசை திரை',
  'Owner app': 'உரிமையாளர் செயலி', 'Sign out': 'வெளியேறு', 'Home': 'முகப்பு', 'Punches': 'பஞ்சுகள்', 'Money': 'பணம்', 'Book': 'முன்பதிவு',
  'Workouts': 'பயிற்சிகள்', 'Pay': 'கட்டணம்', 'Profile': 'சுயவிவரம்', 'More': 'மேலும்',
  // Common
  'Save': 'சேமி', 'Cancel': 'ரத்து', 'Close': 'மூடு', 'Edit': 'திருத்து', 'Delete': 'நீக்கு', 'Search': 'தேடு', 'Add': 'சேர்', 'Back': 'பின்செல்',
  'Loading…': 'ஏற்றுகிறது…', 'Today': 'இன்று', 'Yesterday': 'நேற்று', 'Export': 'ஏற்றுமதி', 'All': 'அனைத்தும்', 'Active': 'செயலில்', 'Expired': 'காலாவதி',
  'Frozen': 'முடக்கப்பட்டது', 'Dues': 'நிலுவை', 'Paid': 'செலுத்தப்பட்டது', 'Inside': 'உள்ளே', 'Name': 'பெயர்', 'Phone': 'தொலைபேசி', 'Plan': 'திட்டம்',
  'Status': 'நிலை', 'Amount': 'தொகை', 'Date': 'தேதி', 'Time': 'நேரம்', 'Total': 'மொத்தம்', 'Balance': 'மீதம்', 'Language': 'மொழி',
  'Good morning': 'காலை வணக்கம்', 'Good afternoon': 'மதிய வணக்கம்', 'Good evening': 'மாலை வணக்கம்',
  '+ Add member': '+ உறுப்பினர் சேர்', 'Record payment': 'கட்டணம் பதிவு', 'Renew plan': 'திட்டம் புதுப்பி', 'Renew': 'புதுப்பி', 'Freeze plan': 'திட்டம் முடக்கு',
  'Inside right now': 'இப்போது உள்ளே', 'Check-ins today': 'இன்றைய வருகை', 'Collected today': 'இன்று வசூல்', 'Dues pending': 'நிலுவை தொகை',
  'Live punch log': 'நேரடி பஞ்ச் பதிவு', 'Renewals this week': 'இந்த வார புதுப்பித்தல்', 'Remind': 'நினைவூட்டு', 'Members inside by hour · today': 'மணி வாரியாக உள்ளே · இன்று',
  'Needs your attention': 'கவனம் தேவை', 'Visit history': 'வருகை வரலாறு', 'Current plan': 'தற்போதைய திட்டம்', 'Progress': 'முன்னேற்றம்',
  // Member app
  'Vanakkam,': 'வணக்கம்,', 'My QR': 'என் QR', 'My card': 'என் அட்டை', "You're in the gym": 'நீங்கள் ஜிம்மில் உள்ளீர்கள்', 'since': 'முதல்',
  'Punch out at the scanner when you leave. Your time is saved to your history.': 'வெளியேறும் போது ஸ்கேனரில் பஞ்ச் அவுட் செய்யவும். உங்கள் நேரம் வரலாற்றில் சேமிக்கப்படும்.',
  'This week': 'இந்த வாரம்', 'visits': 'வருகைகள்', 'days left': 'நாட்கள் மீதம்', "Today's workout from": 'இன்றைய பயிற்சி -', 'exercises': 'பயிற்சிகள்', 'Open': 'திற',
  'PT sessions left': 'PT அமர்வுகள் மீதம்', 'UPI AutoPay on': 'UPI ஆட்டோபே இயக்கத்தில்', 'Hold this at the scanner to punch IN or OUT': 'IN அல்லது OUT பஞ்ச் செய்ய ஸ்கேனரில் காட்டவும்',
  'New code every 30 seconds. Screenshots will not work.': 'ஒவ்வொரு 30 விநாடிக்கும் புதிய குறியீடு. ஸ்கிரீன்ஷாட் வேலை செய்யாது.',
  'Valid till': 'செல்லுபடி', 'Branches': 'கிளைகள்', 'Other ways in': 'மற்ற நுழைவு வழிகள்', 'See my visit history': 'என் வருகை வரலாறு',
  'My visits': 'என் வருகைகள்', 'Time in gym': 'ஜிம்மில் நேரம்', 'Avg stay': 'சராசரி நேரம்', 'Not counted': 'கணக்கில் இல்லை', 'still inside': 'இன்னும் உள்ளே',
  'Forgot to punch out? The session closes at 10:00 PM and is not counted in your time. Ask the front desk to fix it.': 'பஞ்ச் அவுட் மறந்தீர்களா? அமர்வு இரவு 10 மணிக்கு மூடப்படும், நேரத்தில் கணக்கிடப்படாது. முன் மேசையில் சரி செய்யச் சொல்லவும்.',
  'Diet plan': 'உணவு திட்டம்', 'Tap the tick when you finish a meal.': 'உணவு முடித்ததும் டிக் செய்யவும்.', 'My progress': 'என் முன்னேற்றம்', 'Weight': 'எடை',
  'Body fat': 'உடல் கொழுப்பு', 'Waist': 'இடுப்பு', 'Personal records': 'தனிப்பட்ட சாதனைகள்', 'Add photo': 'புகைப்படம் சேர்', 'Log weight': 'எடை பதிவு',
  'Challenges': 'சவால்கள்', 'Leaderboard': 'லீடர்போர்டு', 'From the gym': 'ஜிம்மிலிருந்து', 'Show my name on the leaderboard': 'லீடர்போர்டில் என் பெயரைக் காட்டு',
  'Store': 'கடை', 'Added': 'சேர்க்கப்பட்டது', 'Renew & pay': 'புதுப்பித்து செலுத்து', 'Choose a plan': 'திட்டம் தேர்வு', 'Pay now': 'இப்போது செலுத்து',
  'Use referral credit': 'பரிந்துரை கிரெடிட் பயன்படுத்து', 'Turn on UPI AutoPay': 'UPI ஆட்டோபே இயக்கு', 'Invoices': 'இன்வாய்ஸ்கள்', 'Book a class': 'வகுப்பு முன்பதிவு',
  'Booked': 'முன்பதிவு செய்யப்பட்டது', 'Waitlist': 'காத்திருப்பு', 'Join waitlist': 'காத்திருப்பில் சேர்', 'Cancel booking': 'முன்பதிவு ரத்து', 'spots left': 'இடங்கள் மீதம்',
  'Personal training': 'தனிப்பட்ட பயிற்சி', 'Book PT session': 'PT அமர்வு முன்பதிவு', 'Refer a friend': 'நண்பரைப் பரிந்துரை', 'Sign the waiver': 'ஒப்புதல் படிவத்தில் கையொப்பமிடு',
  'Notifications': 'அறிவிப்புகள்', 'Mobile number': 'மொபைல் எண்', 'Get OTP on WhatsApp': 'WhatsApp-ல் OTP பெறுக', 'Enter the 6-digit code': '6 இலக்க குறியீட்டை உள்ளிடவும்',
  'Verify': 'சரிபார்', 'Use the number you gave at the gym front desk.': 'ஜிம்மில் பதிவு செய்த அதே எண்ணை பயன்படுத்தவும்.', 'Not a member yet?': 'இன்னும் உறுப்பினர் இல்லையா?',
  'Book a free trial': 'இலவச டிரையல் பதிவு', 'Welcome to': 'வரவேற்கிறோம்', 'Your card, visits, plan and workouts in one place.': 'உங்கள் அட்டை, வருகை, திட்டம், பயிற்சிகள் ஒரே இடத்தில்.',
  'Log set': 'செட் பதிவு', 'Last time': 'கடந்த முறை', 'Rest day': 'ஓய்வு நாள்', 'Strava': 'Strava', 'Connect Strava': 'Strava இணை',
  'Payment successful': 'கட்டணம் வெற்றி', 'Dues to pay': 'செலுத்த வேண்டிய நிலுவை', 'Plan ends': 'திட்டம் முடிவு', 'Upcoming': 'வரவிருக்கும்',
};

const I18nContext = createContext({ lang: 'en', setLang: () => {}, t: (s) => s });

export function I18nProvider({ children, initial = 'en' }) {
  const [lang, setLangState] = useState(() => {
    try { return localStorage.getItem('lang') || initial; } catch { return initial; }
  });
  useEffect(() => {
    document.documentElement.lang = lang;
    document.body.classList.toggle('lang-ta', lang === 'ta');
  }, [lang]);
  const setLang = (l) => { setLangState(l); try { localStorage.setItem('lang', l); } catch { /* private mode */ } };
  const t = (s) => (lang === 'ta' && TA[s]) || s;
  return <I18nContext.Provider value={{ lang, setLang, t }}>{children}</I18nContext.Provider>;
}

export const useI18n = () => useContext(I18nContext);

export function LangToggle({ compact = false }) {
  const { lang, setLang } = useI18n();
  return (
    <div className="seg" role="group" aria-label="Language" style={{ width: compact ? 140 : 180 }}>
      <button type="button" className={lang === 'en' ? 'on' : ''} aria-pressed={lang === 'en'} onClick={() => setLang('en')}>English</button>
      <button type="button" className={lang === 'ta' ? 'on' : ''} aria-pressed={lang === 'ta'} onClick={() => setLang('ta')}>தமிழ்</button>
    </div>
  );
}
