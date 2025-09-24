<!DOCTYPE html>
<html style="background: #fff; font-family: 'Avenir Next', Avenir, Roboto, 'Century Gothic', 'Franklin Gothic Medium', 'Helvetica Neue', Helvetica, Arial, sans-serif; font-style: normal">

<head> 
  <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
  <meta charset="utf-8">
  
</head>

<body style="background: #fff; margin: 0; max-width: 100%; padding: 0; width: 100%" bgcolor="#fff">
  <style type="text/css">
    body {
      background: #fff; margin: 0; max-width: 100%; padding: 0; width: 100%;
      }
      img {
      border: 0; outline: none; text-decoration: none;
      }
  </style>
  <table style="background: #fff; margin-left: auto; margin-right: auto; max-width: 768px; padding: 0 20px; width: 100%" bgcolor="#fff">
    <tr>
      <td>
        <table class="tc" style="background: #fff; margin-left: auto; margin-right: auto; max-width: 768px; text-align: center; width: 100%" bgcolor="#fff">
          <tr>
            <td>
              <!--<a href="http://market4opticals.com/">-->
              <!--  <img style="border: 0; display: inline-block; margin: 20px 0; outline: none; text-decoration: none" alt="M4O Logo" src="https://codesandbox.com.ng/market_for_opticals/public/pictures/m4o_logo.png">-->
              <!--</a>-->
            </td>
          </tr>
        </table>
        <table style="background: #fff; border: 1px solid #ccc; border-collapse: separate; border-radius: 3px; margin-left: auto; margin-right: auto; max-width: 768px; padding: 60px 40px; width: 100%" bgcolor="#fff">
          <tr class="tc" style="text-align: center" align="center">
            <td class="f36 lh-title" style="font-size: 36px; line-height: 1.2">Order Confirmation</td>
          </tr>
          <tr class="tc" style="text-align: center" align="center">
            <td class="f18" style="font-size: 18px">
              <p style="margin-bottom: 35px">Hi {{$admins_info['customer_name']}} we received your order and we’ll let you know when we ship it out.</p>
            </td>
          </tr>
          <tr>
            <td class="f18" style="border-collapse: collapse; border-top-color: #e5e5e5; border-top-style: solid; border-top-width: 1px; font-size: 18px; padding: 35px 0 0">
              <p style="font-weight: 500; margin: 0">Your Order:</p>
            </td>
          </tr>
          @foreach($transaction_details['items_bought'] as $item)
          <tr>
            <td style="width: 100%">
              <table class="f18" style="background: #fff; border-collapse: collapse; border-top-color: #e5e5e5; border-top-style: solid; border-top-width: 1px; font-size: 18px; margin: 20px 0 30px; max-width: 768px; padding: 20px 0; width: 100%" bgcolor="#fff">
                <tr style="border-bottom-color: #e5e5e5; border-bottom-style: solid; border-bottom-width: 1px; border-collapse: separate">
                  <td class="tl" style="padding: 20px 0; width: 30%">{{$item->product_quantity}}<span style="color: #999999; padding: 0 10px">x</span>
                    <img width="140" style="border: 0; display: inline-block; max-width: 80%; outline: none; text-decoration: none; vertical-align: middle" src="{{$item->image_url}}" alt="Gkulf1rrdgr9fvft1xr3">
                  </td>
                  <td style="padding: 20px 0 20px 10px; width: 70%">{{$item->product_name}}<span style="float: right"> ₦ {{number_format($item->product_price, 2, '.', ',')}}</span>

                  </td>
                </tr>
              </table>
            </td>
          </tr>
         @endforeach
          <tr>
            <td>
              <table style="background: #fff; margin-left: auto; margin-right: auto; max-width: 768px; padding-bottom: 30px; width: 100%" bgcolor="#fff">
                <tr>
                  <td class="f18" style="font-size: 18px; vertical-align: top; width: 50%" valign="top">
                    <p class="bold" style="font-weight: 600; margin: 0 0 10px">Shipping Address</p>
                    <div class="lh-copy" style="line-height: 1.4">
                      <br>{{$transaction_details['state']}},
                      <br>{{$transaction_details['address']}}</div>
                    </td>
                  <td class="f18" style="font-size: 18px; vertical-align: top; width: 50%" valign="top">
                     <p class="bold" style="font-weight: 600; margin-bottom: 10px">Payment Details</p>
                    <div class="lh-copy" style="line-height: 1.4">Subtotal:<span style="float: right"> ₦{{number_format($transaction_details['subtotal'],2,'.',',')}}</span>

                    </div><br>
                    <div class="lh-copy" style="line-height: 1.4">Shipping fee:<span style="float: right">{{$transaction_details['delivery_fee']}}</span>

                    </div>
                  </td>
                </tr>
              </table>
            </td>
          </tr>
          <tr class="tc" style="text-align: center" align="center">
            <td class="f18 lh-copy" style="border-collapse: collapse; border-top-color: #e5e5e5; border-top-style: solid; border-top-width: 1px; font-size: 18px; line-height: 1.4; padding: 30px 0 0">
              <span style="color: #999999; padding: 0 0 10px">Order Total</span>
              <br> <span class="f36 bold" style="font-size: 36px; font-weight: 600"> ₦{{number_format($transaction_details['total'],2,'.',',')}}</span>

            </td>
          </tr>
          <tr>
            <td style="padding-top: 30px"></td>
          </tr>
          <tr class="tc" style="text-align: center" align="center">
            <td class="f24 lh-title" style="border-collapse: separate; border-top-color: #e5e5e5; border-top-style: solid; border-top-width: 1px; font-size: 24px; line-height: 1.2; padding: 30px 0 15px">Keep in Touch</td>
          </tr>
          <tr class="tc" style="text-align: center" align="center">
            <td class="f18 lh-copy" style="font-size: 18px; line-height: 1.4">If you have any questions, concerns, or suggestions,
              <br>please email us:
              <a href="mailto:".{{$admins_info['superadmin_email']}}>{{$admins_info['superadmin_email']}}</a> <br> or call this number: {{$admins_info['superadmin_phone_number']}}
            </td>
          </tr>
          <!-- social media links -->
          <!--<tr class="tc" style="text-align: center" align="center">-->
          <!--  <td style="padding-top: 30px">-->
          <!--    <a style="display: inline-block" href="https://twitter.com/bluebottleroast">-->
          <!--      <img style="border: 0; display: inline-block; outline: none; text-decoration: none" alt="Twitter logo" src="https://d1yzzccmb3krkj.cloudfront.net/assets/email/twitter-449e556f335ad1854ea6dee8e76c2eb6cb128d4c8180baab8882fcf5786d5199.jpg">-->
          <!--    </a>-->
          <!--    <a style="display: inline-block; padding: 0 25px" href="https://www.facebook.com/bluebottlecoffee">-->
          <!--      <img style="border: 0; display: inline-block; outline: none; text-decoration: none" alt="Facebook logo" src="https://d1yzzccmb3krkj.cloudfront.net/assets/email/facebook-f4b4e236e0f00d4f7a9e1473713d8d28ecfb648de9b9ea2a769781f1ea12759c.jpg">-->
          <!--    </a>-->
          <!--    <a style="display: inline-block" href="https://www.instagram.com/bluebottle/">-->
          <!--      <img style="border: 0; display: inline-block; outline: none; text-decoration: none" alt="Instagram logo" src="https://d1yzzccmb3krkj.cloudfront.net/assets/email/instagram-3a244853b61014407111f5d8a29bd2874681b01ca32e172c322c6b7a2bb79d83.jpg">-->
          <!--    </a>-->
          <!--  </td>-->
          <!--</tr>-->
        </table>
        <table class="tc" style="background: #fff; margin: 30px auto; max-width: 768px; text-align: center; width: 100%" bgcolor="#fff">
          <tr>
            <td class="f14" style="font-size: 14px">© 2022 <span style="font-weight: 600">Market For Opticals</span> All rights reserved.
            </td>
          </tr>
          <tr>
           
          </tr>
        </table>
      </td>
    </tr>
  </table>
  <img src="https://codesandbox.com.ng/market_for_opticals/public/pictures/m4o_logo.png"
    alt="M4O logo" width="1" height="1" border="0" style="height:1px !important;width:1px !important;border-width:0 !important;margin-top:0 !important;margin-bottom:0 !important;margin-right:0 !important;margin-left:0 !important;padding-top:0 !important;padding-bottom:0 !important;padding-right:0 !important;padding-left:0 !important;"
  />
</body>

</html>