/* ===================================================
   Hardcoded location reference data
   - COUNTRIES: world country list (for Country dropdown)
   - PH_LOCATIONS: PSGC-style hierarchy for Region -> Province -> City
     (used only when Country = "Philippines")
   - ASIA_PROVINCES: best-effort Province/State lists for the rest of
     Asia (Country -> Province dropdown, no city drill-down)

   NOTE on accuracy: PH_LOCATIONS.cities does not cover every province,
   and a few ASIA_PROVINCES entries for very large countries (Vietnam,
   Thailand, Turkey, etc.) may be missing a handful of divisions.
   Anything missing automatically falls back to a free-text field in
   the UI, and you can extend these objects any time -- they're plain
   JS objects, no other code changes needed.
   =================================================== */

const COUNTRIES = [
  "Philippines","Afghanistan","Albania","Algeria","Andorra","Angola","Argentina",
  "Armenia","Australia","Austria","Azerbaijan","Bahamas","Bahrain","Bangladesh",
  "Barbados","Belarus","Belgium","Belize","Benin","Bhutan","Bolivia",
  "Bosnia and Herzegovina","Botswana","Brazil","Brunei","Bulgaria","Burkina Faso",
  "Burundi","Cambodia","Cameroon","Canada","Chad","Chile","China","Colombia",
  "Comoros","Costa Rica","Croatia","Cuba","Cyprus","Czech Republic","Denmark",
  "Djibouti","Dominican Republic","Ecuador","Egypt","El Salvador","Estonia",
  "Eswatini","Ethiopia","Fiji","Finland","France","Gabon","Gambia","Georgia",
  "Germany","Ghana","Greece","Guatemala","Guinea","Guyana","Haiti","Honduras",
  "Hong Kong","Hungary","Iceland","India","Indonesia","Iran","Iraq","Ireland",
  "Israel","Italy","Jamaica","Japan","Jordan","Kazakhstan","Kenya","Kuwait",
  "Kyrgyzstan","Laos","Latvia","Lebanon","Lesotho","Liberia","Libya",
  "Liechtenstein","Lithuania","Luxembourg","Macau","Madagascar","Malawi",
  "Malaysia","Maldives","Mali","Malta","Mauritania","Mauritius","Mexico",
  "Moldova","Monaco","Mongolia","Montenegro","Morocco","Mozambique","Myanmar",
  "Namibia","Nepal","Netherlands","New Zealand","Nicaragua","Niger","Nigeria",
  "North Korea","North Macedonia","Norway","Oman","Pakistan","Palau","Panama",
  "Papua New Guinea","Paraguay","Peru","Poland","Portugal","Qatar","Romania",
  "Russia","Rwanda","Saudi Arabia","Senegal","Serbia","Seychelles","Singapore",
  "Slovakia","Slovenia","Somalia","South Africa","South Korea","South Sudan",
  "Spain","Sri Lanka","Sudan","Suriname","Sweden","Switzerland","Syria","Taiwan",
  "Tajikistan","Tanzania","Thailand","Timor-Leste","Togo","Tonga",
  "Trinidad and Tobago","Tunisia","Turkey","Turkmenistan","Uganda","Ukraine",
  "United Arab Emirates","United Kingdom","United States","Uruguay",
  "Uzbekistan","Vanuatu","Vatican City","Venezuela","Vietnam","Yemen","Zambia",
  "Zimbabwe"
];

const PH_LOCATIONS = {
  regions: [
    "NCR - National Capital Region",
    "CAR - Cordillera Administrative Region",
    "Region I - Ilocos Region",
    "Region II - Cagayan Valley",
    "Region III - Central Luzon",
    "Region IV-A - CALABARZON",
    "MIMAROPA Region",
    "Region V - Bicol Region",
    "Region VI - Western Visayas",
    "Region VII - Central Visayas",
    "Region VIII - Eastern Visayas",
    "Region IX - Zamboanga Peninsula",
    "Region X - Northern Mindanao",
    "Region XI - Davao Region",
    "Region XII - SOCCSKSARGEN",
    "Region XIII - Caraga",
    "BARMM - Bangsamoro Autonomous Region in Muslim Mindanao"
  ],

  provinces: {
    "NCR - National Capital Region": ["Metro Manila"],
    "CAR - Cordillera Administrative Region": ["Abra","Apayao","Benguet","Ifugao","Kalinga","Mountain Province"],
    "Region I - Ilocos Region": ["Ilocos Norte","Ilocos Sur","La Union","Pangasinan"],
    "Region II - Cagayan Valley": ["Batanes","Cagayan","Isabela","Nueva Vizcaya","Quirino"],
    "Region III - Central Luzon": ["Aurora","Bataan","Bulacan","Nueva Ecija","Pampanga","Tarlac","Zambales"],
    "Region IV-A - CALABARZON": ["Batangas","Cavite","Laguna","Quezon","Rizal"],
    "MIMAROPA Region": ["Marinduque","Occidental Mindoro","Oriental Mindoro","Palawan","Romblon"],
    "Region V - Bicol Region": ["Albay","Camarines Norte","Camarines Sur","Catanduanes","Masbate","Sorsogon"],
    "Region VI - Western Visayas": ["Aklan","Antique","Capiz","Guimaras","Iloilo","Negros Occidental"],
    "Region VII - Central Visayas": ["Bohol","Cebu","Negros Oriental","Siquijor"],
    "Region VIII - Eastern Visayas": ["Biliran","Eastern Samar","Leyte","Northern Samar","Samar","Southern Leyte"],
    "Region IX - Zamboanga Peninsula": ["Zamboanga del Norte","Zamboanga del Sur","Zamboanga Sibugay"],
    "Region X - Northern Mindanao": ["Bukidnon","Camiguin","Lanao del Norte","Misamis Occidental","Misamis Oriental"],
    "Region XI - Davao Region": ["Davao de Oro","Davao del Norte","Davao del Sur","Davao Occidental","Davao Oriental"],
    "Region XII - SOCCSKSARGEN": ["Cotabato","Sarangani","South Cotabato","Sultan Kudarat"],
    "Region XIII - Caraga": ["Agusan del Norte","Agusan del Sur","Dinagat Islands","Surigao del Norte","Surigao del Sur"],
    "BARMM - Bangsamoro Autonomous Region in Muslim Mindanao": ["Basilan","Lanao del Sur","Maguindanao del Norte","Maguindanao del Sur","Sulu","Tawi-Tawi"]
  },

  /* Not every province is listed. Missing provinces fall back to a
     free-text City field automatically. */
  cities: {
    "Metro Manila": ["Manila","Quezon City","Caloocan","Las Piñas","Makati","Malabon","Mandaluyong","Marikina","Muntinlupa","Navotas","Parañaque","Pasay","Pasig","San Juan","Taguig","Valenzuela","Pateros"],
    "Pampanga": ["Angeles City","San Fernando","Mabalacat","Apalit","Arayat","Bacolor","Candaba","Floridablanca","Guagua","Lubao","Mexico","Minalin","Porac","San Luis","San Simon","Santa Ana","Santa Rita","Santo Tomas","Sasmuan","Magalang"],
    "Bulacan": ["Malolos","Meycauayan","San Jose del Monte","Baliwag","Marilao","Bocaue","Calumpit","Guiguinto","Hagonoy","Plaridel","Pulilan","Santa Maria"],
    "Cavite": ["Bacoor","Dasmariñas","Imus","General Trias","Trece Martires","Tagaytay","Cavite City","Tanza","Naic","Silang"],
    "Laguna": ["Calamba","San Pablo","Santa Rosa","Biñan","Cabuyao","Los Baños","San Pedro","Sta. Cruz"],
    "Batangas": ["Batangas City","Lipa","Tanauan","Sto. Tomas","Bauan","Nasugbu","Lemery"],
    "Rizal": ["Antipolo","Cainta","Taytay","Angono","Binangonan","Rodriguez","San Mateo"],
    "Quezon": ["Lucena","Tayabas","Sariaya","Candelaria","Tiaong","Lucban"],
    "Pangasinan": ["Dagupan","San Carlos","Urdaneta","Alaminos","Lingayen","Binmaley"],
    "Ilocos Norte": ["Laoag","Batac","Paoay","Pagudpud","Sarrat"],
    "Ilocos Sur": ["Vigan","Candon","Narvacan","Santa Maria"],
    "Cagayan": ["Tuguegarao","Aparri","Solana","Gonzaga"],
    "Isabela": ["Ilagan","Cauayan","Santiago","Roxas"],
    "Albay": ["Legazpi","Ligao","Tabaco","Daraga"],
    "Camarines Sur": ["Naga","Iriga","Pili","Calabanga"],
    "Iloilo": ["Iloilo City","Passi","Oton","Pavia"],
    "Negros Occidental": ["Bacolod","Bago","Silay","Talisay","Kabankalan"],
    "Cebu": ["Cebu City","Mandaue City","Lapu-Lapu City","Talisay City","Toledo City","Danao City","Carcar City","Naga City","Bogo City"],
    "Bohol": ["Tagbilaran","Panglao","Tubigon","Ubay"],
    "Leyte": ["Tacloban","Ormoc","Baybay","Palo"],
    "Zamboanga del Sur": ["Zamboanga City","Pagadian","Molave"],
    "Misamis Oriental": ["Cagayan de Oro","Gingoog","El Salvador"],
    "Bukidnon": ["Malaybalay","Valencia","Manolo Fortich"],
    "Davao del Sur": ["Davao City","Digos","Bansalan","Santa Cruz"],
    "South Cotabato": ["General Santos","Koronadal","Polomolok"],
    "Benguet": ["Baguio City","La Trinidad","Itogon"],
    "Palawan": ["Puerto Princesa","Coron","El Nido"]
  }
};

/* Province/State lists for the rest of Asia.
   Country -> array of provinces/states/governorates/etc.
   No city level here by design (Country + Province only). */
const ASIA_PROVINCES = {
  "Afghanistan": ["Badakhshan","Badghis","Baghlan","Balkh","Bamyan","Daykundi","Farah","Faryab","Ghazni","Ghor","Helmand","Herat","Jowzjan","Kabul","Kandahar","Kapisa","Khost","Kunar","Kunduz","Laghman","Logar","Nangarhar","Nimruz","Nuristan","Paktia","Paktika","Panjshir","Parwan","Samangan","Sar-e Pol","Takhar","Urozgan","Wardak","Zabul"],
  "Armenia": ["Aragatsotn","Ararat","Armavir","Gegharkunik","Kotayk","Lori","Shirak","Syunik","Tavush","Vayots Dzor","Yerevan"],
  "Azerbaijan": ["Absheron","Aran","Baku","Ganja-Gazakh","Guba-Khachmaz","Karabakh","Lankaran","Nakhchivan","Shaki-Zaqatala","Sumgait"],
  "Bahrain": ["Capital","Muharraq","Northern","Southern"],
  "Bangladesh": ["Barisal","Chittagong","Dhaka","Khulna","Mymensingh","Rajshahi","Rangpur","Sylhet"],
  "Bhutan": ["Bumthang","Chukha","Dagana","Gasa","Haa","Lhuentse","Mongar","Paro","Pemagatshel","Punakha","Samdrup Jongkhar","Samtse","Sarpang","Thimphu","Trashigang","Trashiyangtse","Trongsa","Tsirang","Wangdue Phodrang","Zhemgang"],
  "Brunei": ["Brunei-Muara","Belait","Tutong","Temburong"],
  "Cambodia": ["Banteay Meanchey","Battambang","Kampong Cham","Kampong Chhnang","Kampong Speu","Kampong Thom","Kampot","Kandal","Kep","Koh Kong","Kratié","Mondulkiri","Oddar Meanchey","Pailin","Phnom Penh","Preah Vihear","Prey Veng","Pursat","Ratanakiri","Siem Reap","Preah Sihanouk","Stung Treng","Svay Rieng","Takéo","Tboung Khmum"],
  "China": ["Beijing","Tianjin","Hebei","Shanxi","Inner Mongolia","Liaoning","Jilin","Heilongjiang","Shanghai","Jiangsu","Zhejiang","Anhui","Fujian","Jiangxi","Shandong","Henan","Hubei","Hunan","Guangdong","Guangxi","Hainan","Chongqing","Sichuan","Guizhou","Yunnan","Tibet","Shaanxi","Gansu","Qinghai","Ningxia","Xinjiang","Hong Kong","Macau","Taiwan"],
  "Cyprus": ["Nicosia","Limassol","Larnaca","Famagusta","Paphos","Kyrenia"],
  "Georgia": ["Adjara","Abkhazia","Guria","Imereti","Kakheti","Kvemo Kartli","Mtskheta-Mtianeti","Racha-Lechkhumi and Kvemo Svaneti","Samegrelo-Zemo Svaneti","Samtskhe-Javakheti","Shida Kartli","Tbilisi"],
  "Hong Kong": ["Hong Kong Island","Kowloon","New Territories"],
  "India": ["Andhra Pradesh","Arunachal Pradesh","Assam","Bihar","Chhattisgarh","Goa","Gujarat","Haryana","Himachal Pradesh","Jharkhand","Karnataka","Kerala","Madhya Pradesh","Maharashtra","Manipur","Meghalaya","Mizoram","Nagaland","Odisha","Punjab","Rajasthan","Sikkim","Tamil Nadu","Telangana","Tripura","Uttar Pradesh","Uttarakhand","West Bengal","Andaman and Nicobar Islands","Chandigarh","Dadra and Nagar Haveli and Daman and Diu","Delhi","Jammu and Kashmir","Ladakh","Lakshadweep","Puducherry"],
  "Indonesia": ["Aceh","North Sumatra","West Sumatra","Riau","Riau Islands","Jambi","South Sumatra","Bangka Belitung","Bengkulu","Lampung","Banten","Jakarta","West Java","Central Java","Yogyakarta","East Java","Bali","West Nusa Tenggara","East Nusa Tenggara","West Kalimantan","Central Kalimantan","South Kalimantan","East Kalimantan","North Kalimantan","North Sulawesi","Gorontalo","Central Sulawesi","West Sulawesi","South Sulawesi","Southeast Sulawesi","Maluku","North Maluku","West Papua","Papua","Central Papua","Highland Papua","South Papua","Southwest Papua"],
  "Iran": ["Alborz","Ardabil","East Azerbaijan","West Azerbaijan","Bushehr","Chaharmahal and Bakhtiari","Fars","Gilan","Golestan","Hamadan","Hormozgan","Ilam","Isfahan","Kerman","Kermanshah","Khuzestan","Kohgiluyeh and Boyer-Ahmad","Kurdistan","Lorestan","Markazi","Mazandaran","North Khorasan","Qazvin","Qom","Razavi Khorasan","Semnan","Sistan and Baluchestan","South Khorasan","Tehran","Yazd","Zanjan"],
  "Iraq": ["Baghdad","Basra","Nineveh","Erbil","Sulaymaniyah","Duhok","Kirkuk","Anbar","Babil","Karbala","Najaf","Wasit","Diyala","Salah al-Din","Dhi Qar","Maysan","Muthanna","Qadisiyyah"],
  "Israel": ["Central","Haifa","Jerusalem","Northern","Southern","Tel Aviv"],
  "Japan": ["Hokkaido","Aomori","Iwate","Miyagi","Akita","Yamagata","Fukushima","Ibaraki","Tochigi","Gunma","Saitama","Chiba","Tokyo","Kanagawa","Niigata","Toyama","Ishikawa","Fukui","Yamanashi","Nagano","Gifu","Shizuoka","Aichi","Mie","Shiga","Kyoto","Osaka","Hyogo","Nara","Wakayama","Tottori","Shimane","Okayama","Hiroshima","Yamaguchi","Tokushima","Kagawa","Ehime","Kochi","Fukuoka","Saga","Nagasaki","Kumamoto","Oita","Miyazaki","Kagoshima","Okinawa"],
  "Jordan": ["Amman","Ajloun","Aqaba","Balqa","Irbid","Jerash","Karak","Ma'an","Madaba","Mafraq","Tafilah","Zarqa"],
  "Kazakhstan": ["Almaty City","Astana","Shymkent","Almaty Region","Akmola","Aktobe","Atyrau","East Kazakhstan","Jambyl","Karagandy","Kostanay","Kyzylorda","Mangystau","North Kazakhstan","Pavlodar","West Kazakhstan","Turkistan","Ulytau","Abai","Jetisu"],
  "Kuwait": ["Al Asimah","Hawalli","Farwaniya","Mubarak Al-Kabeer","Ahmadi","Jahra"],
  "Kyrgyzstan": ["Batken","Chuy","Jalal-Abad","Naryn","Osh Region","Talas","Issyk-Kul","Bishkek City","Osh City"],
  "Laos": ["Vientiane Prefecture","Vientiane Province","Phongsali","Luang Namtha","Oudomxay","Bokeo","Luang Prabang","Huaphanh","Xayaboury","Xiangkhouang","Bolikhamxay","Khammouane","Savannakhet","Salavan","Sekong","Champasak","Attapeu","Xaisomboun"],
  "Lebanon": ["Beirut","Mount Lebanon","North Lebanon","Akkar","Baalbek-Hermel","Beqaa","South Lebanon","Nabatieh"],
  "Macau": ["Macau Peninsula","Taipa","Coloane"],
  "Malaysia": ["Johor","Kedah","Kelantan","Malacca","Negeri Sembilan","Pahang","Penang","Perak","Perlis","Sabah","Sarawak","Selangor","Terengganu","Kuala Lumpur","Labuan","Putrajaya"],
  "Maldives": ["Malé","Addu","Fuvahmulah","Haa Alif","Haa Dhaalu","Shaviyani","Noonu","Raa","Baa","Lhaviyani","Kaafu","Alif Alif","Alif Dhaalu","Vaavu","Meemu","Faafu","Dhaalu","Thaa","Laamu","Gaafu Alif","Gaafu Dhaalu","Gnaviyani"],
  "Mongolia": ["Arkhangai","Bayan-Ölgii","Bayankhongor","Bulgan","Darkhan-Uul","Dornod","Dornogovi","Dundgovi","Govi-Altai","Govisümber","Khentii","Khovd","Khövsgöl","Ömnögovi","Orkhon","Övörkhangai","Selenge","Sükhbaatar","Töv","Uvs","Zavkhan","Ulaanbaatar"],
  "Myanmar": ["Kachin","Kayah","Kayin","Chin","Mon","Rakhine","Shan","Sagaing","Bago","Magway","Mandalay","Yangon","Ayeyarwady","Tanintharyi","Naypyidaw"],
  "Nepal": ["Koshi","Madhesh","Bagmati","Gandaki","Lumbini","Karnali","Sudurpashchim"],
  "North Korea": ["Pyongyang","Nampo","Rason","Chagang","North Hamgyong","South Hamgyong","North Hwanghae","South Hwanghae","Kangwon","North Pyongan","South Pyongan","Ryanggang"],
  "Oman": ["Muscat","Dhofar","Musandam","Al Buraimi","Ad Dakhiliyah","Al Batinah North","Al Batinah South","Al Sharqiyah North","Al Sharqiyah South","Ad Dhahirah","Al Wusta"],
  "Pakistan": ["Punjab","Sindh","Khyber Pakhtunkhwa","Balochistan","Gilgit-Baltistan","Azad Kashmir","Islamabad Capital Territory"],
  "Qatar": ["Doha","Al Rayyan","Al Wakrah","Al Khor","Umm Salal","Al Daayen","Al Shamal","Al Shahaniya"],
  "Saudi Arabia": ["Riyadh","Makkah","Madinah","Eastern Province","Asir","Tabuk","Qassim","Hail","Northern Borders","Jazan","Najran","Al Bahah","Al Jouf"],
  "Singapore": ["Central Region","East Region","North Region","North-East Region","West Region"],
  "South Korea": ["Seoul","Busan","Daegu","Incheon","Gwangju","Daejeon","Ulsan","Sejong","Gyeonggi","Gangwon","North Chungcheong","South Chungcheong","North Jeolla","South Jeolla","North Gyeongsang","South Gyeongsang","Jeju"],
  "Sri Lanka": ["Central","Eastern","North Central","Northern","North Western","Sabaragamuwa","Southern","Uva","Western"],
  "Syria": ["Damascus","Rif Dimashq","Aleppo","Homs","Hama","Latakia","Idlib","Al-Hasakah","Deir ez-Zor","Raqqa","Daraa","As-Suwayda","Quneitra","Tartus"],
  "Taiwan": ["Taipei","New Taipei","Taoyuan","Taichung","Tainan","Kaohsiung","Keelung","Hsinchu","Chiayi","Hualien","Yilan","Miaoli","Changhua","Nantou","Yunlin","Pingtung","Taitung","Penghu","Kinmen","Lienchiang"],
  "Tajikistan": ["Sughd","Khatlon","Districts of Republican Subordination","Gorno-Badakhshan","Dushanbe"],
  "Thailand": ["Bangkok","Amnat Charoen","Ang Thong","Bueng Kan","Buriram","Chachoengsao","Chai Nat","Chaiyaphum","Chanthaburi","Chiang Mai","Chiang Rai","Chonburi","Chumphon","Kalasin","Kamphaeng Phet","Kanchanaburi","Khon Kaen","Krabi","Lampang","Lamphun","Loei","Lopburi","Mae Hong Son","Maha Sarakham","Mukdahan","Nakhon Nayok","Nakhon Pathom","Nakhon Phanom","Nakhon Ratchasima","Nakhon Sawan","Nakhon Si Thammarat","Nan","Narathiwat","Nong Bua Lamphu","Nong Khai","Nonthaburi","Pathum Thani","Pattani","Phang Nga","Phatthalung","Phayao","Phetchabun","Phetchaburi","Phichit","Phitsanulok","Phrae","Phuket","Prachinburi","Prachuap Khiri Khan","Ranong","Ratchaburi","Rayong","Roi Et","Sa Kaeo","Sakon Nakhon","Samut Prakan","Samut Sakhon","Samut Songkhram","Saraburi","Satun","Sing Buri","Sisaket","Songkhla","Sukhothai","Suphan Buri","Surat Thani","Surin","Tak","Trang","Trat","Ubon Ratchathani","Udon Thani","Uthai Thani","Uttaradit","Yala","Yasothon"],
  "Timor-Leste": ["Aileu","Ainaro","Baucau","Bobonaro","Covalima","Dili","Ermera","Lautém","Liquiçá","Manatuto","Manufahi","Oecusse","Viqueque"],
  "Turkey": ["Istanbul","Ankara","Izmir","Bursa","Antalya","Adana","Konya","Gaziantep","Mersin","Kayseri","Eskisehir","Diyarbakir","Samsun","Denizli","Sanliurfa","Malatya","Erzurum","Van","Trabzon","Manisa","Balikesir","Kahramanmaras","Ordu","Mugla","Aydin","Tekirdag","Sakarya","Kocaeli","Hatay","Elazig","Adiyaman","Afyonkarahisar","Amasya","Artvin","Bilecik","Bingol","Bitlis","Bolu","Burdur","Canakkale"],
  "Turkmenistan": ["Ahal","Balkan","Dashoguz","Lebap","Mary","Ashgabat"],
  "United Arab Emirates": ["Abu Dhabi","Dubai","Sharjah","Ajman","Umm Al Quwain","Ras Al Khaimah","Fujairah"],
  "Uzbekistan": ["Andijan","Bukhara","Fergana","Jizzakh","Namangan","Navoiy","Qashqadaryo","Karakalpakstan","Samarqand","Sirdaryo","Surxondaryo","Tashkent Region","Tashkent City","Xorazm"],
  "Vietnam": ["Hanoi","Ho Chi Minh City","Hai Phong","Da Nang","Can Tho","An Giang","Ba Ria-Vung Tau","Bac Giang","Bac Kan","Bac Lieu","Bac Ninh","Ben Tre","Binh Dinh","Binh Duong","Binh Phuoc","Binh Thuan","Ca Mau","Cao Bang","Dak Lak","Dak Nong","Dien Bien","Dong Nai","Dong Thap","Gia Lai","Ha Giang","Ha Nam","Ha Tinh","Hai Duong","Hau Giang","Hoa Binh","Hung Yen","Khanh Hoa","Kien Giang","Kon Tum","Lai Chau","Lam Dong","Lang Son","Lao Cai","Long An","Nam Dinh","Nghe An","Ninh Binh","Ninh Thuan","Phu Tho","Phu Yen","Quang Binh","Quang Nam","Quang Ngai","Quang Ninh","Quang Tri","Soc Trang","Son La","Tay Ninh","Thai Binh","Thai Nguyen","Thanh Hoa","Thua Thien Hue","Tien Giang","Tra Vinh","Tuyen Quang","Vinh Long","Vinh Phuc","Yen Bai"],
  "Yemen": ["Sanaa","Aden","Taiz","Hadhramaut","Hodeidah","Ibb","Al Dhale'e","Al Jawf","Al Mahrah","Al Mahwit","Amran","Dhamar","Hajjah","Lahij","Marib","Raymah","Saada","Shabwah","Socotra","Abyan"]
};