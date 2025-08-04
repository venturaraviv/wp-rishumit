<?php
/**
 * The template for displaying 404 pages (not found)
 */
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>404 - דף לא נמצא</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: Arial, sans-serif;
            background-color: #f5f5f5;
            height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            direction: rtl;
        }

        .container {
            background-color: white;
            padding: 40px;
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            text-align: center;
            max-width: 500px;
            width: 90%;
        }

        .container h1 {
            color: #333;
            font-size: 24px;
            margin-bottom: 20px;
            font-weight: bold;
        }

        .container p {
            color: #666;
            line-height: 1.6;
            margin-bottom: 30px;
            font-size: 16px;
        }

        #goBackButton {
            background-color: #1e3a5f;
            color: white;
            padding: 12px 40px;
            border: none;
            border-radius: 5px;
            font-size: 16px;
            cursor: pointer;
            transition: background-color 0.3s ease;
            font-weight: 500;
            width: 100%;
        }

        #goBackButton:hover {
            background-color: #2c5282;
        }

        @media (max-width: 768px) {
            .container {
                padding: 30px 20px;
            }
            
            .container h1 {
                font-size: 20px;
            }
            
            .container p {
                font-size: 14px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>הדף שחיפשת אינו קיים</h1>
        <p>ייתכן שהגעת לקישור שאינו פעיל. לחץ על הכפתור למטה כדי לחזור לדף הבית.</p>
        <button id="goBackButton">חזרה</button>
    </div>

    <script type="text/javascript">
        document.getElementById('goBackButton').addEventListener('click', function() {
            window.location.href = "<?php echo home_url(); ?>";
        });
    </script>

    <script type="application/ld+json">
    {
      "@context": "https://schema.org",
      "@type": "WebPage",
      "name": "שגיאה 404 - דף לא נמצא",
      "description": "דף זה מיועד למשתמשים שנכנסו לקישור שאינו פעיל ומציע חזרה לדף הבית.",
      "breadcrumb": {
        "@type": "BreadcrumbList",
        "itemListElement": [
          {
            "@type": "ListItem",
            "position": 1,
            "name": "בית",
            "item": "<?php echo home_url(); ?>"
          },
          {
            "@type": "ListItem",
            "position": 2,
            "name": "שגיאה 404",
            "item": "<?php echo home_url(); ?>/404"
          }
        ]
      },
      "publisher": {
        "@type": "Organization",
        "name": "רישומית אונליין בע\"מ",
        "url": "<?php echo home_url(); ?>",
        "logo": "<?php echo home_url(); ?>/wp-content/uploads/2024/12/1.png",
        "contactPoint": {
          "@type": "ContactPoint",
          "email": "service@rishumit.online",
          "telephone": "+972-54-8021224",
          "contactType": "שירות לקוחות",
          "availableLanguage": ["Hebrew", "English", "Russian"]
        },
        "address": {
          "@type": "PostalAddress",
          "streetAddress": "תוצרת הארץ 3, מגדלי ב.ס.ר",
          "addressLocality": "פתח תקווה",
          "addressRegion": "מרכז",
          "addressCountry": "IL"
        }
      }
    }
    </script>
</body>
</html>