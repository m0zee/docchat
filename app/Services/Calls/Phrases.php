<?php

namespace App\Services\Calls;

/**
 * The calling agent's fixed lines in each supported language. Answers come
 * from the model in the caller's language; these are the lines around them.
 * Each greeting still says it is a virtual assistant.
 *
 * Placeholders: {name} the assistant, {workspace}, {first} " Sara" or "",
 * {about} the purpose sentence (or "").
 */
final class Phrases
{
    public const LANGUAGES = ['en', 'es', 'fr', 'de', 'hi', 'ur', 'ar'];

    public const NAMES = [
        'en' => 'English', 'es' => 'Spanish', 'fr' => 'French', 'de' => 'German',
        'hi' => 'Hindi', 'ur' => 'Urdu', 'ar' => 'Arabic',
    ];

    /** @var array<string, array<string, string|list<string>>> */
    private const LINES = [
        'en' => [
            'greeting' => "Hi, this is {name}, {workspace}'s virtual assistant. How can I help you today?",
            'disclosure' => "I'm a virtual assistant.",
            'outbound' => 'Hi{first}, this is {name}, a virtual assistant calling for {workspace}{about}.',
            'about' => ' about {purpose}',
            'outbound_default' => 'I can answer your questions from our documents. What would you like to know?',
            'opt_out_hint' => "If you'd rather not get these calls, just say stop calling.",
            'not_heard' => ["Sorry, I didn't catch that. What would you like to know?", 'Sorry, could you say that again?'],
            'silent_goodbye' => "I can't hear anything, so I'll end the call now. Goodbye.",
            'goodbye' => 'Thanks for calling. Bye for now!',
            'opted_out' => "Understood. You won't get any more calls from us. Goodbye.",
            'trouble' => "Sorry, I'm having trouble answering right now. Please try again in a minute.",
            'time_up' => "We've reached the time limit for this call. Thank you, goodbye.",
            'not_found' => "I couldn't find that in our documents.",
            'anything_else' => 'Is there anything else I can help with?',
            'fillers' => ['Hmm, let me check.', 'One moment.', 'Sure, just a second.', 'Okay, let me look that up.'],
            'voicemail' => 'Hi, this is {name}, a virtual assistant calling for {workspace}{about}. Please call us back when it suits you. Goodbye.',
            'speech_retry' => "Sorry, I couldn't hear that properly. Could you say it again, or type it?",
            'speech_down' => "Sorry, voice isn't working right now. Please type your question.",
        ],
        'es' => [
            'greeting' => 'Hola, soy {name}, asistente virtual de {workspace}. ¿En qué puedo ayudarte?',
            'disclosure' => 'Soy asistente virtual.',
            'outbound' => 'Hola{first}, soy {name}, asistente virtual de {workspace}.{about}',
            'about' => ' Te llamo sobre {purpose}.',
            'outbound_default' => 'Puedo responder tus preguntas con nuestros documentos. ¿Qué te gustaría saber?',
            'opt_out_hint' => 'Si prefieres no recibir estas llamadas, solo di: no me llames más.',
            'not_heard' => ['Perdona, no te he entendido bien. ¿Qué te gustaría saber?', 'Perdona, ¿puedes repetirlo?'],
            'silent_goodbye' => 'No te escucho, así que voy a terminar la llamada. Adiós.',
            'goodbye' => 'Gracias por llamar. ¡Hasta luego!',
            'opted_out' => 'Entendido. No volverás a recibir llamadas nuestras. Adiós.',
            'trouble' => 'Perdona, ahora mismo tengo problemas para responder. Inténtalo de nuevo en un minuto.',
            'time_up' => 'Hemos llegado al límite de tiempo de esta llamada. Gracias, adiós.',
            'not_found' => 'No he encontrado eso en nuestros documentos.',
            'anything_else' => '¿Puedo ayudarte con algo más?',
            'fillers' => ['Mmm, déjame ver.', 'Un momento.', 'Vale, lo compruebo.'],
            'voicemail' => 'Hola, soy {name}, asistente virtual de {workspace}.{about} Llámanos cuando te venga bien. Adiós.',
            'speech_retry' => 'Perdona, no te he oído bien. ¿Puedes repetirlo o escribirlo?',
            'speech_down' => 'Perdona, la voz no funciona ahora mismo. Escribe tu pregunta, por favor.',
        ],
        'fr' => [
            'greeting' => 'Bonjour, ici {name}, assistant virtuel de {workspace}. Comment puis-je vous aider ?',
            'disclosure' => 'Je suis un assistant virtuel.',
            'outbound' => 'Bonjour{first}, ici {name}, assistant virtuel de {workspace}.{about}',
            'about' => ' Je vous appelle au sujet de {purpose}.',
            'outbound_default' => 'Je peux répondre à vos questions à partir de nos documents. Que souhaitez-vous savoir ?',
            'opt_out_hint' => "Si vous préférez ne plus recevoir ces appels, dites simplement : arrêtez de m'appeler.",
            'not_heard' => ["Pardon, je n'ai pas bien compris. Que souhaitez-vous savoir ?", 'Pardon, pouvez-vous répéter ?'],
            'silent_goodbye' => "Je ne vous entends pas, je vais donc mettre fin à l'appel. Au revoir.",
            'goodbye' => 'Merci de votre appel. Au revoir !',
            'opted_out' => "C'est noté. Vous ne recevrez plus d'appels de notre part. Au revoir.",
            'trouble' => "Désolé, j'ai du mal à répondre pour le moment. Réessayez dans une minute.",
            'time_up' => 'Nous avons atteint la durée maximale de cet appel. Merci, au revoir.',
            'not_found' => "Je n'ai pas trouvé cette information dans nos documents.",
            'anything_else' => 'Puis-je vous aider pour autre chose ?',
            'fillers' => ['Hmm, voyons voir.', 'Un instant.', "D'accord, je vérifie."],
            'voicemail' => 'Bonjour, ici {name}, assistant virtuel de {workspace}.{about} Rappelez-nous quand cela vous arrange. Au revoir.',
            'speech_retry' => "Pardon, je n'ai pas bien entendu. Pouvez-vous répéter ou l'écrire ?",
            'speech_down' => 'Désolé, la voix ne fonctionne pas pour le moment. Veuillez écrire votre question.',
        ],
        'de' => [
            'greeting' => 'Hallo, hier ist {name}, die virtuelle Assistenz von {workspace}. Wie kann ich Ihnen helfen?',
            'disclosure' => 'Ich bin eine virtuelle Assistenz.',
            'outbound' => 'Hallo{first}, hier ist {name}, die virtuelle Assistenz von {workspace}.{about}',
            'about' => ' Ich rufe wegen {purpose} an.',
            'outbound_default' => 'Ich kann Ihre Fragen anhand unserer Dokumente beantworten. Was möchten Sie wissen?',
            'opt_out_hint' => 'Wenn Sie diese Anrufe nicht mehr möchten, sagen Sie einfach: bitte nicht mehr anrufen.',
            'not_heard' => ['Entschuldigung, das habe ich nicht verstanden. Was möchten Sie wissen?', 'Entschuldigung, können Sie das wiederholen?'],
            'silent_goodbye' => 'Ich höre nichts, deshalb beende ich jetzt den Anruf. Auf Wiederhören.',
            'goodbye' => 'Danke für Ihren Anruf. Auf Wiederhören!',
            'opted_out' => 'Verstanden. Sie bekommen keine Anrufe mehr von uns. Auf Wiederhören.',
            'trouble' => 'Entschuldigung, ich kann gerade nicht antworten. Bitte versuchen Sie es in einer Minute noch einmal.',
            'time_up' => 'Wir haben die Zeitgrenze für diesen Anruf erreicht. Danke, auf Wiederhören.',
            'not_found' => 'Das habe ich in unseren Dokumenten nicht gefunden.',
            'anything_else' => 'Kann ich sonst noch helfen?',
            'fillers' => ['Hmm, einen Moment.', 'Ich schaue kurz nach.', 'Okay, eine Sekunde.'],
            'voicemail' => 'Hallo, hier ist {name}, die virtuelle Assistenz von {workspace}.{about} Bitte rufen Sie uns zurück, wenn es Ihnen passt. Auf Wiederhören.',
            'speech_retry' => 'Entschuldigung, das habe ich nicht gut gehört. Können Sie es wiederholen oder tippen?',
            'speech_down' => 'Entschuldigung, die Sprachfunktion geht gerade nicht. Bitte tippen Sie Ihre Frage.',
        ],
        'hi' => [
            'greeting' => 'नमस्ते, मैं {name} हूँ, {workspace} की ओर से एक वर्चुअल असिस्टेंट। बताइए, मैं आपकी कैसे मदद करूँ?',
            'disclosure' => 'मैं एक वर्चुअल असिस्टेंट हूँ।',
            'outbound' => 'नमस्ते{first}, मैं {name} हूँ, {workspace} की ओर से एक वर्चुअल असिस्टेंट।{about}',
            'about' => ' यह कॉल {purpose} के बारे में है।',
            'outbound_default' => 'आप हमारे दस्तावेज़ों के बारे में कुछ भी पूछ सकते हैं। आप क्या जानना चाहेंगे?',
            'opt_out_hint' => 'अगर आप ये कॉल नहीं चाहते, तो बस कहिए: कॉल मत करो।',
            'not_heard' => ['माफ़ कीजिए, आवाज़ साफ़ नहीं आई। आप क्या जानना चाहेंगे?', 'क्या आप फिर से कह सकते हैं?'],
            'silent_goodbye' => 'कुछ सुनाई नहीं दे रहा, इसलिए कॉल खत्म की जा रही है। नमस्ते।',
            'goodbye' => 'कॉल करने के लिए धन्यवाद। नमस्ते!',
            'opted_out' => 'ठीक है। अब आपको हमारी ओर से कॉल नहीं आएगी। नमस्ते।',
            'trouble' => 'माफ़ कीजिए, अभी जवाब देने में दिक्कत हो रही है। कृपया एक मिनट बाद फिर कोशिश करें।',
            'time_up' => 'इस कॉल का समय पूरा हो गया है। धन्यवाद, नमस्ते।',
            'not_found' => 'यह जानकारी हमारे दस्तावेज़ों में नहीं मिली।',
            'anything_else' => 'क्या मैं और किसी चीज़ में मदद करूँ?',
            'fillers' => ['हम्म, एक सेकंड।', 'एक पल रुकिए।', 'ठीक है, ज़रा देखते हैं।'],
            'voicemail' => 'नमस्ते, मैं {name} हूँ, {workspace} की ओर से एक वर्चुअल असिस्टेंट।{about} जब सुविधा हो, हमें वापस कॉल करें। नमस्ते।',
            'speech_retry' => 'माफ़ कीजिए, आवाज़ ठीक से नहीं आई। कृपया फिर से कहिए या लिख दीजिए।',
            'speech_down' => 'माफ़ कीजिए, अभी आवाज़ काम नहीं कर रही। कृपया अपना सवाल लिखिए।',
        ],
        'ur' => [
            'greeting' => 'السلام علیکم، میں {name} ہوں، {workspace} کی طرف سے ایک ورچوئل اسسٹنٹ۔ بتائیے، میں آپ کی کیا مدد کروں؟',
            'disclosure' => 'میں ایک ورچوئل اسسٹنٹ ہوں۔',
            'outbound' => 'السلام علیکم{first}، میں {name} ہوں، {workspace} کی طرف سے ایک ورچوئل اسسٹنٹ۔{about}',
            'about' => ' یہ کال {purpose} کے بارے میں ہے۔',
            'outbound_default' => 'آپ ہماری دستاویزات کے بارے میں کچھ بھی پوچھ سکتے ہیں۔ آپ کیا جاننا چاہیں گے؟',
            'opt_out_hint' => 'اگر آپ یہ کالز نہیں چاہتے تو بس کہیے: کال مت کرو۔',
            'not_heard' => ['معاف کیجیے، آواز صاف نہیں آئی۔ آپ کیا جاننا چاہیں گے؟', 'کیا آپ دوبارہ کہہ سکتے ہیں؟'],
            'silent_goodbye' => 'کچھ سنائی نہیں دے رہا، اس لیے کال ختم کی جا رہی ہے۔ خدا حافظ۔',
            'goodbye' => 'کال کرنے کا شکریہ۔ خدا حافظ!',
            'opted_out' => 'ٹھیک ہے۔ اب آپ کو ہماری طرف سے کال نہیں آئے گی۔ خدا حافظ۔',
            'trouble' => 'معاف کیجیے، ابھی جواب دینے میں مشکل ہو رہی ہے۔ براہ کرم ایک منٹ بعد دوبارہ کوشش کریں۔',
            'time_up' => 'اس کال کا وقت پورا ہو گیا ہے۔ شکریہ، خدا حافظ۔',
            'not_found' => 'یہ معلومات ہماری دستاویزات میں نہیں ملی۔',
            'anything_else' => 'کیا میں کسی اور چیز میں مدد کروں؟',
            'fillers' => ['ہمم، ایک سیکنڈ۔', 'ذرا ایک لمحہ۔', 'ٹھیک ہے، دیکھتے ہیں۔'],
            'voicemail' => 'السلام علیکم، میں {name} ہوں، {workspace} کی طرف سے ایک ورچوئل اسسٹنٹ۔{about} جب سہولت ہو، ہمیں واپس کال کریں۔ خدا حافظ۔',
            'speech_retry' => 'معاف کیجیے، آواز ٹھیک سے نہیں آئی۔ براہ کرم دوبارہ کہیے یا لکھ دیجیے۔',
            'speech_down' => 'معاف کیجیے، ابھی آواز کام نہیں کر رہی۔ براہ کرم اپنا سوال لکھیے۔',
        ],
        'ar' => [
            'greeting' => 'مرحباً، معك {name}، المساعد الافتراضي لـ {workspace}. كيف يمكنني مساعدتك؟',
            'disclosure' => 'أنا مساعد افتراضي.',
            'outbound' => 'مرحباً{first}، معك {name}، المساعد الافتراضي لـ {workspace}.{about}',
            'about' => ' أتصل بخصوص {purpose}.',
            'outbound_default' => 'يمكنني الإجابة عن أسئلتك من مستنداتنا. ماذا تود أن تعرف؟',
            'opt_out_hint' => 'إذا كنت لا ترغب في هذه المكالمات، فقط قل: توقف عن الاتصال.',
            'not_heard' => ['عذراً، لم أسمعك جيداً. ماذا تود أن تعرف؟', 'عذراً، هل يمكنك أن تعيد ذلك؟'],
            'silent_goodbye' => 'لا أسمع شيئاً، لذلك سأنهي المكالمة الآن. مع السلامة.',
            'goodbye' => 'شكراً لاتصالك. مع السلامة!',
            'opted_out' => 'حسناً. لن تصلك منا أي مكالمات بعد الآن. مع السلامة.',
            'trouble' => 'عذراً، أواجه مشكلة في الإجابة الآن. يرجى المحاولة بعد دقيقة.',
            'time_up' => 'وصلنا إلى الحد الزمني لهذه المكالمة. شكراً لك، مع السلامة.',
            'not_found' => 'لم أجد ذلك في مستنداتنا.',
            'anything_else' => 'هل هناك شيء آخر يمكنني مساعدتك فيه؟',
            'fillers' => ['حسناً، لحظة من فضلك.', 'دعني أتحقق.', 'ثانية واحدة.'],
            'voicemail' => 'مرحباً، معك {name}، المساعد الافتراضي لـ {workspace}.{about} يرجى معاودة الاتصال بنا في الوقت المناسب لك. مع السلامة.',
            'speech_retry' => 'عذراً، لم أسمع ذلك جيداً. هل يمكنك تكراره أو كتابته؟',
            'speech_down' => 'عذراً، الصوت لا يعمل الآن. يرجى كتابة سؤالك.',
        ],
    ];

    /**
     * A line, with placeholders filled. Lines with variants pick one by $turn,
     * so a call doesn't repeat itself but tests stay predictable.
     *
     * @param  array<string, string>  $values
     */
    public static function get(string $language, string $key, array $values = [], int $turn = 0): string
    {
        $line = self::LINES[$language][$key] ?? self::LINES['en'][$key];

        if (is_array($line)) {
            $line = $line[$turn % count($line)];
        }

        return strtr($line, collect($values)->mapWithKeys(fn ($value, $name) => ['{'.$name.'}' => $value])->all());
    }

    /**
     * @return list<string>
     */
    public static function all(string $language, string $key): array
    {
        $line = self::LINES[$language][$key] ?? self::LINES['en'][$key];

        return is_array($line) ? $line : [$line];
    }

    public static function languageName(string $language): string
    {
        return self::NAMES[$language] ?? 'English';
    }

    /**
     * Does the text already say it's an assistant (in any supported language)?
     */
    public static function discloses(string $text): bool
    {
        return (bool) preg_match(
            '/\b(assistant|assistente|asistente|assistenz|assistent|virtual|virtuel|virtuell|automated|automatic|AI|A\.I\.|KI|IA|bot|robot)\b|असिस्टेंट|ورچوئل|اسسٹنٹ|مساعد|آلي/iu',
            $text,
        );
    }

    public static function isGoodbye(string $heard): bool
    {
        $words = preg_split('/\s+/u', trim($heard)) ?: [];

        return count($words) <= 7 && (bool) preg_match(
            "/\b(bye|good ?bye|that'?s all|that is all|nothing else|no,? thanks?|no,? thank you|hang up|end (the )?call"
            .'|adi[oó]s|hasta luego|au revoir|tsch[uü]ss|auf wiederh[oö]ren|auf wiedersehen|khuda hafiz|allah hafiz|alvida)\b'
            .'|अलविदा|خدا حافظ|اللہ حافظ|مع السلامة/iu',
            $heard,
        );
    }

    public static function isOptOut(string $heard): bool
    {
        return (bool) preg_match(
            "/\b(stop calling|don'?t call|do not call|never call|remove me|unsubscribe|opt (me )?out"
            ."|no me llames|ne m'appelez plus|arr[eê]tez de m'appeler|nicht mehr anrufen|call mat karo)\b"
            .'|कॉल मत करो|कॉल मत करना|کال مت کرو|کال نہ کریں|توقف عن الاتصال|لا تتصل/iu',
            $heard,
        );
    }
}
